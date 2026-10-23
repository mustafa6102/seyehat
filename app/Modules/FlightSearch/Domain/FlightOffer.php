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
