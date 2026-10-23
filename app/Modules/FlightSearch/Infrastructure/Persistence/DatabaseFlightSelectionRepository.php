<?php

namespace App\Modules\FlightSearch\Infrastructure\Persistence;

use App\Modules\FlightSearch\Domain\FlightOffer;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * SCH-TripRequestFlightSelection v3.13 storage: at most one row per TripRequest (trip_request_id is unique). Only the
 * canonical offer contract is stored - never a raw provider response. The latest server-side verification of an
 * offer (state + captured_at of OP-repriceOffer) lives next to the cached offer and expires with it.
 */
final readonly class DatabaseFlightSelectionRepository
{
    private const TABLE = 'trip_request_flight_selections';

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
     * Stores $offer as the TripRequest's CURRENT selection. A new selection replaces the previous one (no history);
     * a reconfirmation keeps the selection's created_at.
     */
    public function store(string $tripRequestId, FlightOffer $offer, DateTimeImmutable $verifiedAt, bool $reconfirmation): void
    {
        $source = $offer->sources[0];
        // RULE-FLT-REPRICE-001-07: only a known, complete total is a verified price; anything else stays null.
        $price = $offer->calculatedPrice->completeness === 'COMPLETE' ? $offer->calculatedPrice->value : null;
        $now = $this->toUtc(new DateTimeImmutable('now'));
        $values = [
            'status' => 'CURRENT',
            'offer_snapshot' => json_encode($offer->toContract($verifiedAt), JSON_THROW_ON_ERROR),
            'provider_id' => $source->providerId,
            'provider_offer_id' => $source->providerOfferId,
            'verified_price_minor_units' => $price?->minorUnits,
            'verified_price_currency' => $price?->currency,
            'verified_at' => $this->toUtc($verifiedAt),
            'expires_at' => $this->toUtc($offer->expiresAt ?? $verifiedAt),
            'updated_at' => $now,
        ];
        if (! $reconfirmation) {
            $values['created_at'] = $now;
        }

        $this->database->table(self::TABLE)->updateOrInsert(['trip_request_id' => $tripRequestId], $values);
        // Pre-filter for TRN-FSEL-02: a selected offer can only be repriced while it is still cached.
        if ($offer->expiresAt !== null) {
            $this->cache->put($this->selectedKey($source->providerId, $source->providerOfferId), true, $offer->expiresAt);
        }
    }

    /** TRN-FSEL-02: CURRENT -> RECONFIRMATION_REQUIRED for every selection of the changed offer; content is kept. */
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

    /** TRN-FSEL-03 */
    public function markExpired(string $tripRequestId): void
    {
        $this->database->table(self::TABLE)
            ->where('trip_request_id', $tripRequestId)
            ->whereIn('status', ['CURRENT', 'RECONFIRMATION_REQUIRED'])
            ->update(['status' => 'EXPIRED', 'updated_at' => $this->toUtc(new DateTimeImmutable('now'))]);
    }

    /** Approved v3.13 rule: the offer must appear in one of this TripRequest's flight search results. */
    public function tripRequestHasOffer(string $tripRequestId, string $offerId): bool
    {
        $results = $this->database->table('flight_search_jobs')
            ->where('trip_request_id', $tripRequestId)
            ->whereNotNull('result_json')
            ->pluck('result_json');

        foreach ($results as $json) {
            $offers = json_decode((string) $json, true)['offers'] ?? [];
            foreach (is_array($offers) ? $offers : [] as $offer) {
                if (is_array($offer) && ($offer['provider_offer_id'] ?? null) === $offerId) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Latest OP-repriceOffer result for the offer: OfferState and the snapshot's captured_at (DATE_ATOM). */
    public function rememberVerification(FlightOffer $offer, string $state, DateTimeImmutable $capturedAt): void
    {
        $this->cache->put(
            $this->verificationKey($offer->providerOfferId),
            ['state' => $state, 'captured_at' => $capturedAt->format(DATE_ATOM)],
            $offer->expiresAt !== null && $offer->expiresAt > $capturedAt ? $offer->expiresAt : 3600,
        );
    }

    /** @return array{state: string, captured_at: string}|null */
    public function latestVerification(string $offerId): ?array
    {
        $verification = $this->cache->get($this->verificationKey($offerId));

        return is_array($verification) ? $verification : null;
    }

    private function verificationKey(string $offerId): string
    {
        return 'flight-offer-verification:'.hash('sha256', $offerId);
    }

    private function selectedKey(string $providerId, string $providerOfferId): string
    {
        return 'flight-offer-selected:'.hash('sha256', $providerId.'|'.$providerOfferId);
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
