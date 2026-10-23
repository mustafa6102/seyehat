<?php

namespace App\Modules\Accommodation\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * SCH-TripRequestAccommodationSelection v3.14 storage: at most one row per TripRequest (trip_request_id is unique). Only
 * the canonical AccommodationOffer is stored - never a raw provider response or a quote id.
 */
final readonly class DatabaseAccommodationSelectionRepository
{
    private const TABLE = 'trip_request_accommodation_selections';

    public function __construct(private ConnectionInterface $database, private CacheRepository $cache) {}

    /** @return array<string, mixed>|null */
    public function find(string $tripRequestId): ?array
    {
        $row = $this->database->table(self::TABLE)->where('trip_request_id', $tripRequestId)->first();
        if ($row === null) {
            return null;
        }

        return [
            'trip_request_id' => (string) $row->trip_request_id,
            'status' => $row->status,
            'offer' => json_decode($row->offer_snapshot, true, flags: JSON_THROW_ON_ERROR),
            'provider_id' => $row->provider_id,
            'provider_offer_id' => $row->provider_offer_id,
            'verified_price' => $row->verified_price_minor_units === null || $row->verified_price_currency === null
                ? null
                : ['minor_units' => (int) $row->verified_price_minor_units, 'currency' => $row->verified_price_currency],
            'verified_at' => $this->toAtom($row->verified_at),
            'expires_at' => $this->toAtom($row->expires_at),
            'created_at' => $this->toAtom($row->created_at),
            'updated_at' => $this->toAtom($row->updated_at),
        ];
    }

    /**
     * Stores the verified $offer as the TripRequest's CURRENT selection; a new selection replaces the previous one (no
     * history), a reconfirmation keeps created_at.
     *
     * @param  array<string, mixed>  $offer  SCH-AccommodationOffer with source.verified_at and expires_at
     */
    public function store(string $tripRequestId, array $offer, bool $reconfirmation): void
    {
        $price = $offer['total_price'] ?? [];
        // RULE-ACC-001-08: only a known, complete total is a verified price; anything else stays null (never 0).
        $verified = ($price['completeness'] ?? null) === 'COMPLETE' && is_array($price['value'] ?? null) ? $price['value'] : null;
        $now = $this->toUtc(new DateTimeImmutable('now'));
        $values = [
            'status' => 'CURRENT',
            'offer_snapshot' => json_encode($offer, JSON_THROW_ON_ERROR),
            'provider_id' => $offer['source']['provider_id'],
            'provider_offer_id' => $offer['source']['provider_offer_id'],
            'verified_price_minor_units' => $verified['minor_units'] ?? null,
            'verified_price_currency' => $verified['currency'] ?? null,
            'verified_at' => $this->toUtc(new DateTimeImmutable($offer['source']['verified_at'])),
            'expires_at' => $this->toUtc(new DateTimeImmutable($offer['expires_at'])),
            'updated_at' => $now,
        ];
        if (! $reconfirmation) {
            $values['created_at'] = $now;
        }

        $this->database->table(self::TABLE)->updateOrInsert(['trip_request_id' => $tripRequestId], $values);
        // Pre-filter for TRN-ACCSEL-02: a selected offer can only be revalidated while it is still stored.
        $this->cache->put($this->selectedKey($values['provider_id'], $values['provider_offer_id']), true, new DateTimeImmutable($offer['expires_at']));
    }

    /** TRN-ACCSEL-02: CURRENT -> RECONFIRMATION_REQUIRED for selections of the changed offer; content is kept. */
    public function requireReconfirmation(string $providerId, string $providerOfferId): void
    {
        if (! $this->cache->has($this->selectedKey($providerId, $providerOfferId))) {
            return;
        }

        $this->database->table(self::TABLE)
            ->where('provider_id', $providerId)
            ->where('provider_offer_id', $providerOfferId)
            ->where('status', 'CURRENT')
            ->update(['status' => 'RECONFIRMATION_REQUIRED', 'updated_at' => $this->toUtc(new DateTimeImmutable('now'))]);
    }

    /** TRN-ACCSEL-03 */
    public function markExpired(string $tripRequestId): void
    {
        $this->database->table(self::TABLE)
            ->where('trip_request_id', $tripRequestId)
            ->whereIn('status', ['CURRENT', 'RECONFIRMATION_REQUIRED'])
            ->update(['status' => 'EXPIRED', 'updated_at' => $this->toUtc(new DateTimeImmutable('now'))]);
    }

    /** The offer must appear in one of this TripRequest's accommodation search results (TRN-ACCSEL-01). */
    public function tripRequestHasOffer(string $tripRequestId, string $offerId): bool
    {
        $results = $this->database->table('accommodation_search_jobs')
            ->where('trip_request_id', $tripRequestId)
            ->whereNotNull('result_json')
            ->pluck('result_json');

        foreach ($results as $json) {
            $offers = json_decode((string) $json, true)['offers'] ?? [];
            foreach (is_array($offers) ? $offers : [] as $offer) {
                if (is_array($offer) && ($offer['offer_id'] ?? null) === $offerId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function selectedKey(string $providerId, string $providerOfferId): string
    {
        return 'accommodation-offer-selected:'.hash('sha256', $providerId.'|'.$providerOfferId);
    }

    private function toUtc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function toAtom(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
