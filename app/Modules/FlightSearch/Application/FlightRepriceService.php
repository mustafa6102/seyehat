<?php

namespace App\Modules\FlightSearch\Application;

use App\Modules\FlightSearch\Contracts\FlightRepriceGateway;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use App\Modules\FlightSearch\Domain\OfferChangeSet;
use App\Modules\FlightSearch\Domain\OfferSnapshot;
use App\Modules\FlightSearch\Domain\ProviderTimeout;
use App\Modules\FlightSearch\Domain\RepriceFailed;
use App\Modules\FlightSearch\Domain\RepriceReason;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityView;
use DateTimeImmutable;

final readonly class FlightRepriceService
{
    private const UNSUPPORTED = 'unsupported';

    private const TIMED_OUT = 'timed_out';

    private const FAILED = 'failed';

    private const ITINERARY_CHANGED = 'itinerary_changed';

    public function __construct(
        private FlightOfferRepository $offers,
        private ProviderCapabilityMatrix $capabilities,
        private FlightRepriceGateway $gateway,
    ) {}

    public function reprice(string $offerId, ?DateTimeImmutable $capturedAt = null): RepriceResult
    {
        $capturedAt ??= new DateTimeImmutable('now');
        $previous = $this->offers->find($offerId);
        if ($previous === null) {
            throw new OfferNotFound;
        }
        $previousSnapshot = new OfferSnapshot($previous, $capturedAt);

        $providerId = $previous->sources[0]->providerId;
        $capability = $this->capabilities->productionCapability($providerId, 'reprice');
        if (! $capability->supported) {
            return $this->unverified($previousSnapshot, $previous, $capturedAt, $capability, self::UNSUPPORTED);
        }

        try {
            $current = $this->gateway->reprice($providerId, $previous);
        } catch (ProviderTimeout) {
            return $this->unverified($previousSnapshot, $previous, $capturedAt, $capability, self::TIMED_OUT);
        } catch (RepriceFailed) {
            return $this->unverified($previousSnapshot, $previous, $capturedAt, $capability, self::FAILED);
        }
        // OD-022 D3: a different itinerary (segments, order or direction) under the same offer id is not evidence for
        // the stored offer; it is UNVERIFIED and the stored offer and any selection stay as they are.
        if ($current->itinerarySignature() !== $previous->itinerarySignature()) {
            return $this->unverified($previousSnapshot, $previous, $capturedAt, $capability, self::ITINERARY_CHANGED);
        }

        $changes = $this->changes($previous, $current);
        $expired = $current->state($capturedAt) === 'EXPIRED';
        $state = $expired ? 'EXPIRED' : ($changes->criticalChange ? 'CHANGED' : 'CURRENT');
        $current = $current->withLifecycleState($state);
        $this->offers->save($current);

        $reasonCodes = $changes->criticalChange ? [RepriceReason::OFFER_CHANGED] : [];
        if ($expired) {
            $reasonCodes[] = RepriceReason::OFFER_EXPIRED;
        }
        $approvalValid = ! $changes->criticalChange && ! $expired;

        return new RepriceResult(
            previous: $previousSnapshot,
            current: new OfferSnapshot($current, $capturedAt),
            changes: $changes,
            reasonCodes: $reasonCodes,
            previousApprovalValid: $approvalValid,
            continuationAllowed: $approvalValid,
            repriceCapability: $capability,
            messageTr: $expired
                ? 'Teklifin geçerlilik süresi doldu; işlem devam edemez.'
                : ($changes->criticalChange
                    ? 'Teklifin fiyatı veya kritik koşulları değişti. Yeni onay gerekiyor.'
                    : 'Teklif yeniden doğrulandı ve değişmedi.'),
        );
    }

    private function unverified(
        OfferSnapshot $previousSnapshot,
        FlightOffer $offer,
        DateTimeImmutable $capturedAt,
        ProviderCapabilityView $capability,
        string $cause,
    ): RepriceResult {
        $expired = $offer->state($capturedAt) === 'EXPIRED';
        $state = $expired ? 'EXPIRED' : 'UNVERIFIED';
        $offer = $offer->withLifecycleState($state);
        $this->offers->save($offer);

        $reasons = $cause === self::TIMED_OUT
            ? [RepriceReason::PROVIDER_TIMEOUT, RepriceReason::REPRICE_UNVERIFIED]
            : [RepriceReason::REPRICE_UNVERIFIED];
        if ($expired) {
            $reasons[] = RepriceReason::OFFER_EXPIRED;
        }

        return new RepriceResult(
            previous: $previousSnapshot,
            current: new OfferSnapshot($offer, $capturedAt),
            changes: new OfferChangeSet(false, false, false),
            reasonCodes: $reasons,
            previousApprovalValid: false,
            continuationAllowed: false,
            repriceCapability: $capability,
            messageTr: $expired
                ? 'Teklifin geçerlilik süresi doldu; işlem devam edemez.'
                : match ($cause) {
                    self::TIMED_OUT => 'Sağlayıcı yanıtı zamanında alınamadı; teklif doğrulanamadı.',
                    self::FAILED => 'Teklif sağlayıcıdan doğrulanamadı; güncel olarak gösterilmez.',
                    self::ITINERARY_CHANGED => 'Sağlayıcı bu teklif için farklı bir uçuş güzergâhı döndürdü; teklif doğrulanamadı ve seçiminiz değiştirilmedi.',
                    default => 'Sağlayıcı yeniden fiyatlamayı desteklemiyor; fiyat değişebilir.',
                },
        );
    }

    /**
     * Critical changes compare what the offer says, not when it was read: a fresh provider response carries
     * new fetch/verification/calculation timestamps even when price, fare terms and baggage are identical.
     */
    private function changes(FlightOffer $previous, FlightOffer $current): OfferChangeSet
    {
        return new OfferChangeSet(
            priceChanged: $this->priceTerms($previous) !== $this->priceTerms($current),
            fareConditionsChanged: $this->fareTerms($previous) !== $this->fareTerms($current),
            baggageChanged: $previous->baggage->fingerprint() !== $current->baggage->fingerprint(),
        );
    }

    /** @return array<string, mixed> */
    private function priceTerms(FlightOffer $offer): array
    {
        return array_intersect_key(
            $offer->calculatedPrice->toContract(),
            array_flip(['value', 'certainty', 'completeness', 'known_lower_bound', 'unknown_components', 'fx_snapshot_id']),
        );
    }

    /** @return list<array{key: mixed, value: mixed, certainty: mixed}> */
    private function fareTerms(FlightOffer $offer): array
    {
        return array_map(
            fn (array $term): array => ['key' => $term['key'], 'value' => $term['value'], 'certainty' => $term['certainty']],
            $offer->fareConditions->terms,
        );
    }
}
