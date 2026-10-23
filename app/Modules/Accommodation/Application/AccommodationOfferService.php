<?php

namespace App\Modules\Accommodation\Application;

use App\Modules\Accommodation\Infrastructure\Duffel\DuffelStaysAdapter;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * OP-getAccommodationOffer / OP-revalidateAccommodationOffer (v3.14, AccommodationOfferState TRN-ACCOFFER-01..05).
 * Server-side canonical offers live in the cache until their provider expires_at; the latest stored snapshot is the
 * server's latest verification. Nothing about an offer is taken from the browser.
 */
final readonly class AccommodationOfferService
{
    public function __construct(
        private CacheRepository $cache,
        private DuffelStaysAdapter $stays,
        private ProviderCapabilityMatrix $capabilities,
    ) {}

    /** @param  list<array<string, mixed>>  $offers  discovered SCH-AccommodationOffer (TRN-ACCOFFER-04) */
    public function remember(array $offers): void
    {
        foreach ($offers as $offer) {
            $this->store($offer);
        }
    }

    /** @return array<string, mixed>|null unexpired stored offer */
    public function find(string $offerId): ?array
    {
        $offer = $this->cache->get($this->key($offerId));

        return is_array($offer) && new DateTimeImmutable((string) $offer['expires_at']) > new DateTimeImmutable('now') ? $offer : null;
    }

    /**
     * @return array<string, mixed>|null SCH-AccommodationRevalidationResult, or null when the offer is unknown/expired
     */
    public function revalidate(string $offerId, ?DateTimeImmutable $now = null): ?array
    {
        $previous = $this->find($offerId);
        if ($previous === null) {
            return null;
        }
        $capturedAt = $now ?? new DateTimeImmutable('now');
        $previousSnapshot = [
            'offer' => $previous,
            'captured_at' => (string) ($previous['source']['verified_at'] ?? $previous['source']['fetched_at']),
        ];

        if (new DateTimeImmutable((string) $previous['expires_at']) <= $capturedAt) {
            return $this->result($previousSnapshot, ['state' => 'EXPIRED'] + $previous, $capturedAt, null, ['RC-EVIDENCE-OFFER-EXPIRED']);
        }

        // OD-023: credential plus FRESH/STALE production capability evidence before any provider call.
        try {
            $supported = DuffelStaysAdapter::isConfigured()
                && $this->capabilities->productionCapability(DuffelStaysAdapter::PROVIDER_ID, 'accommodation_revalidate')->supported;
        } catch (Throwable) {
            $supported = false;
        }
        try {
            if (! $supported) {
                throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
            }
            $current = $this->stays->quote($previous, $capturedAt);
        } catch (ProviderFailure $failure) {
            // TRN-ACCOFFER-03: an earlier verification is not presented as current evidence.
            $unverified = ['state' => 'UNVERIFIED', 'source' => ['verified_at' => null] + $previous['source']] + $previous;
            $reasons = $failure->kind === ProviderFailureKind::TIMEOUT
                ? ['RC-PROVIDER-TIMEOUT', 'RC-EVIDENCE-REPRICE-UNVERIFIED']
                : ['RC-EVIDENCE-REPRICE-UNVERIFIED'];

            return $this->result($previousSnapshot, $unverified, $capturedAt, null, $reasons);
        }

        $changes = $this->changes($previous, $current);
        $expired = new DateTimeImmutable((string) $current['expires_at']) <= $capturedAt;
        $state = $expired ? 'EXPIRED' : ($changes['critical_change'] ? 'CHANGED' : 'CURRENT');
        $current = ['state' => $state, 'source' => ['verified_at' => $capturedAt->format(DATE_ATOM)] + $current['source']] + $current;
        $reasons = array_values(array_filter([
            $changes['critical_change'] ? 'RC-EVIDENCE-OFFER-CHANGED' : null,
            $expired ? 'RC-EVIDENCE-OFFER-EXPIRED' : null,
        ]));

        return $this->result($previousSnapshot, $current, $capturedAt, $changes, $reasons);
    }

    /**
     * Material comparison (v3.14 AccommodationRevalidationResult.changes); timestamps are never compared.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array{price_changed: bool, mandatory_fees_changed: bool, availability_changed: bool, cancellation_changed: bool, critical_change: bool}
     */
    private function changes(array $previous, array $current): array
    {
        $price = fn (array $offer): array => array_intersect_key($offer['total_price'], array_flip(['value', 'known_lower_bound', 'certainty']));
        $fees = fn (array $offer): array => array_intersect_key($offer['total_price'], array_flip(['completeness', 'unknown_components']));
        $stay = fn (array $offer): array => [$offer['property_id'], $offer['room_description'], $offer['check_in_date'], $offer['check_out_date']];
        $changes = [
            'price_changed' => $price($previous) !== $price($current),
            'mandatory_fees_changed' => $fees($previous) !== $fees($current),
            'availability_changed' => $stay($previous) !== $stay($current),
            'cancellation_changed' => $previous['cancellation'] !== $current['cancellation'],
        ];

        return $changes + ['critical_change' => in_array(true, $changes, true)];
    }

    /**
     * Stores the current snapshot as the latest server-side verification and builds the contract result.
     *
     * @param  array<string, mixed>  $previousSnapshot
     * @param  array<string, mixed>  $current
     * @param  array<string, bool>|null  $changes
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function result(array $previousSnapshot, array $current, DateTimeImmutable $capturedAt, ?array $changes, array $reasons): array
    {
        $this->store($current);

        return [
            'previous' => $previousSnapshot,
            'current' => ['offer' => $current, 'captured_at' => $capturedAt->format(DATE_ATOM)],
            'changes' => $changes ?? ['price_changed' => false, 'mandatory_fees_changed' => false, 'availability_changed' => false, 'cancellation_changed' => false, 'critical_change' => false],
            'reason_codes' => $reasons,
        ];
    }

    /** @param  array<string, mixed>  $offer */
    private function store(array $offer): void
    {
        try {
            $expiresAt = new DateTimeImmutable((string) $offer['expires_at']);
        } catch (Throwable) {
            return;
        }
        // An expired offer is not kept: OP-getAccommodationOffer answers 404 for it.
        if ($expiresAt > new DateTimeImmutable('now')) {
            $this->cache->put($this->key((string) $offer['offer_id']), $offer, $expiresAt);
        } else {
            $this->cache->forget($this->key((string) $offer['offer_id']));
        }
    }

    private function key(string $offerId): string
    {
        return 'accommodation-offer:'.hash('sha256', $offerId);
    }
}
