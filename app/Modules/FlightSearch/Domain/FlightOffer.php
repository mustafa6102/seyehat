<?php

namespace App\Modules\FlightSearch\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class FlightOffer
{
    public readonly CalculatedOfferPrice $calculatedPrice;

    public readonly FareConditionSnapshot $fareConditions;

    /**
     * @param  list<ProviderSource>  $sources
     * @param  list<FlightSegment>  $segments
     */
    public function __construct(
        public readonly string $providerOfferId,
        public readonly Money $price,
        public readonly string $priceCertainty,
        public readonly string $priceCompleteness,
        public readonly BaggageAllowance $baggage,
        public readonly ?DateTimeImmutable $expiresAt,
        public array $sources,
        public array $segments,
        public bool $bookable,
        public readonly string $lifecycleState = 'CURRENT',
        ?CalculatedOfferPrice $calculatedPrice = null,
        ?FareConditionSnapshot $fareConditions = null,
    ) {
        if (! in_array($priceCertainty, ['EXACT', 'ESTIMATED', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('Invalid price certainty.');
        }
        if (! in_array($priceCompleteness, ['COMPLETE', 'PARTIAL'], true)) {
            throw new InvalidArgumentException('Invalid price completeness.');
        }
        if ($segments === []) {
            throw new InvalidArgumentException('FlightOffer requires at least one segment.');
        }
        // FR-FLT-002-04: one contiguous ordered list, all OUTBOUND segments first, then all INBOUND segments.
        $inbound = false;
        foreach ($segments as $index => $segment) {
            if ($segment->direction === 'INBOUND') {
                $inbound = true;
            } elseif ($inbound || $index !== 0 && $segment->direction !== 'OUTBOUND') {
                throw new InvalidArgumentException('FlightOffer segments must be OUTBOUND first, then INBOUND.');
            }
        }
        if ($segments[0]->direction !== 'OUTBOUND') {
            throw new InvalidArgumentException('FlightOffer segments must start with OUTBOUND.');
        }
        if (! in_array($lifecycleState, ['CURRENT', 'CHANGED', 'EXPIRED', 'UNVERIFIED'], true)) {
            throw new InvalidArgumentException('Invalid OfferState.');
        }

        $this->calculatedPrice = $calculatedPrice ?? new CalculatedOfferPrice(
            value: $priceCompleteness === 'COMPLETE' ? $price : null,
            knownLowerBound: $priceCompleteness === 'PARTIAL' ? $price : null,
            unknownComponents: $priceCompleteness === 'PARTIAL' ? ['BASE_PRICE_PARTIAL'] : [],
            completeness: $priceCompleteness,
            certainty: $priceCertainty,
            fxSnapshotId: null,
            inputRefs: ['offer:'.$providerOfferId.':price'],
            calculationVersion: 'v3.3',
            calculatedAt: $sources[0]->fetchedAt ?? new DateTimeImmutable('now'),
        );
        $this->fareConditions = $fareConditions ?? new FareConditionSnapshot;
    }

    /** SCH-FlightOffer 3.23 journey_type: derived from the provider slices the segments came from (OD-022 D1). */
    public function journeyType(): string
    {
        foreach ($this->segments as $segment) {
            if ($segment->direction === 'INBOUND') {
                return 'ROUND_TRIP';
            }
        }

        return 'ONE_WAY';
    }

    /**
     * OD-022 D3: the physical itinerary including direction. A reprice returning a different value for the same
     * offer id is not evidence for the selected offer.
     */
    public function itinerarySignature(): string
    {
        return implode('>', array_map(
            fn (FlightSegment $segment, int $index): string => $segment->direction.'|'.$segment->physicalFingerprint($index + 1),
            $this->segments,
            array_keys($this->segments),
        ));
    }

    /**
     * OD-022 D2 (SCH-FlightOffer 3.23 x-legacy-read): a stored v3.4 offer contract without journey_type/direction can
     * only have come from a single-slice provider offer, so it reads as ONE_WAY with OUTBOUND segments. Nothing else
     * changes and already-v3.23 contracts are returned as they are.
     *
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>
     */
    public static function normalizeLegacyContract(array $offer): array
    {
        // Only a stored FlightOffer contract is normalized; anything else is returned untouched.
        if (array_key_exists('journey_type', $offer) || ! array_key_exists('provider_offer_id', $offer)
            || ! is_array($offer['segments'] ?? null)) {
            return $offer;
        }
        $offer['segments'] = array_map(
            fn (mixed $segment): mixed => is_array($segment) && ! array_key_exists('direction', $segment) ? ['direction' => 'OUTBOUND'] + $segment : $segment,
            $offer['segments'],
        );

        return ['provider_offer_id' => $offer['provider_offer_id'], 'journey_type' => 'ONE_WAY'] + $offer;
    }

    public function itineraryFingerprint(): string
    {
        return implode('>', array_map(
            fn (FlightSegment $segment, int $index): string => $segment->physicalFingerprint($index + 1),
            $this->segments,
            array_keys($this->segments),
        ));
    }

    public function deduplicationKey(): string
    {
        return implode('#', [
            $this->itineraryFingerprint(),
            $this->price->currency,
            (string) $this->price->minorUnits,
            $this->priceCertainty,
            $this->priceCompleteness,
            $this->baggage->fingerprint(),
        ]);
    }

    public function merge(self $other): void
    {
        foreach ($other->sources as $source) {
            $this->sources[] = $source;
        }
        usort($this->sources, fn (ProviderSource $a, ProviderSource $b): int => $a->providerId <=> $b->providerId);
        foreach ($this->segments as $index => $segment) {
            $segment->mergeMarketingReferences($other->segments[$index]);
        }
        $this->bookable = $this->bookable || $other->bookable;
    }

    public function state(?DateTimeImmutable $asOf = null): string
    {
        $asOf ??= new DateTimeImmutable('now');

        return $this->expiresAt !== null && $this->expiresAt <= $asOf ? 'EXPIRED' : $this->lifecycleState;
    }

    public function withLifecycleState(string $state): self
    {
        return new self(
            providerOfferId: $this->providerOfferId,
            price: $this->price,
            priceCertainty: $this->priceCertainty,
            priceCompleteness: $this->priceCompleteness,
            baggage: $this->baggage,
            expiresAt: $this->expiresAt,
            sources: $this->sources,
            segments: $this->segments,
            bookable: $state === 'CURRENT' && $this->bookable,
            lifecycleState: $state,
            calculatedPrice: $this->calculatedPrice,
            fareConditions: $this->fareConditions,
        );
    }

    /** @return array<string, mixed> */
    public function toContract(?DateTimeImmutable $asOf = null): array
    {
        return [
            'provider_offer_id' => $this->providerOfferId,
            'journey_type' => $this->journeyType(),
            'state' => $this->state($asOf),
            'price' => $this->calculatedPrice->toContract(),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'baggage' => $this->baggage->toContract(),
            'bookable' => $this->bookable,
            'sources' => array_map(fn (ProviderSource $source): array => array_intersect_key(
                $source->toContract($asOf),
                array_flip(['provider_id', 'provider_offer_id', 'provider_role', 'distribution_channel', 'fetched_at', 'verified_at', 'expires_at', 'verification_status']),
            ), $this->sources),
            'segments' => array_map(fn (FlightSegment $segment): array => $segment->toContract(), $this->segments),
        ];
    }
}
