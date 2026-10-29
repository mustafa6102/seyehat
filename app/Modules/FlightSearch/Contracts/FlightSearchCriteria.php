<?php

namespace App\Modules\FlightSearch\Contracts;

use App\Modules\Api\Contracts\TripJourney;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\ProviderAcl\Contracts\AviationIdentity;
use InvalidArgumentException;

/**
 * SCH-FlightSearchCriteria v3.12: untransformed copy of a SEARCHABLE TripRequest's search fields.
 * Places are SCH-LocationRef v3.8 including the optional provider-independent aviation_identity; the journey
 * (journey_type, outbound_date, return_date for ROUND_TRIP) is carried unchanged (RULE-TRIP-001-04/05).
 * Nothing is converted or guessed. Search-only passenger composition does not close OD-020/OD-021.
 * preferences are not flight criteria.
 */
final readonly class FlightSearchCriteria
{
    /**
     * @param  array{kind: string, value: string, iana_timezone: string, aviation_identity?: array{type: string, code: string}}  $originPlace
     * @param  array{kind: string, value: string, iana_timezone: string, aviation_identity?: array{type: string, code: string}}  $destinationPlace
     * @param  array{type: string, date?: string, start_date?: string, end_date?: string}  $outboundDate
     * @param  array{type: string, date?: string, start_date?: string, end_date?: string}|null  $returnDate  only for ROUND_TRIP
     */
    private function __construct(
        public string $tripRequestId,
        public array $originPlace,
        public array $destinationPlace,
        public string $journeyType,
        public array $outboundDate,
        public ?array $returnDate,
        public int $travelerCount,
        public ?array $passengerComposition,
    ) {}

    /**
     * @throws InvalidArgumentException when the TripRequest is not SEARCHABLE
     * @throws UnsupportedFlightSearchDate when the journey is legacy (v3.2 date_window) or not a valid v3.9 journey
     */
    public static function fromSearchableTripRequest(string $tripRequestId, TripRequestView $tripRequest): self
    {
        if ($tripRequestId === '' || $tripRequest->status !== 'SEARCHABLE' || $tripRequest->travelerCount < 1) {
            throw new InvalidArgumentException('Only a SEARCHABLE TripRequest yields flight search criteria.');
        }
        if ($tripRequest->isLegacyDateModel()) {
            // A pre-v3.9 date_window has no known direction; it is never read as an outbound date or a trip span.
            throw UnsupportedFlightSearchDate::legacy();
        }
        $journey = TripJourney::fromContract($tripRequest->journeyType, $tripRequest->outboundDate, $tripRequest->returnDate);
        if ($journey === null) {
            throw new UnsupportedFlightSearchDate;
        }

        return new self(
            $tripRequestId,
            $tripRequest->originPlace,
            $tripRequest->destinationPlace,
            $journey->type,
            $journey->outbound->toContract(),
            $journey->return?->toContract(),
            $tripRequest->travelerCount,
            $tripRequest->passengerComposition,
        );
    }

    /**
     * OD-022 LOCATION (v3.8): Turkish reason why these locations cannot be sent to a flight provider, or null.
     * Allowed: kind airport with IATA_AIRPORT, kind city with IATA_CITY (city-to-airport expansion is the
     * provider's, never ours). Stations are not M1A flight input (GND-001, M1C); any other kind, a missing or
     * invalid identity, or a kind/identity mismatch is blocked before any FlightProviderAdapter call.
     */
    public function locationBlockerTr(): ?string
    {
        foreach ([$this->originPlace, $this->destinationPlace] as $place) {
            if ($place['kind'] === 'station') {
                return 'İstasyon konumları uçuş aramasında kullanılamaz.';
            }
        }
        foreach ([$this->originPlace, $this->destinationPlace] as $place) {
            $identity = AviationIdentity::fromContract($place['aviation_identity'] ?? null);
            if ($identity === null || ! $identity->matchesKind($place['kind'])) {
                return 'Uçuş araması için başlangıç ve varış konumlarının havalimanı veya şehir aramasından seçilmiş olması gerekir; bu seyahat talebindeki konumların havacılık kimliği (IATA kodu) yok.';
            }
        }

        return null;
    }

    /**
     * OD-022 (v3.23 remaining_open "FLEXIBLE date provider search"): Turkish reason why this journey cannot be searched
     * with any provider yet, or null. EXACT/EXACT ROUND_TRIP is searchable since v3.23 (D1) as one multi-slice request.
     * FLEXIBLE: no provider date selection is defined. Nothing is converted (no split into one-way searches, no date
     * picked from a range).
     */
    public function journeyBlockerTr(): ?string
    {
        $reasons = [];
        foreach ([$this->outboundDate, $this->returnDate] as $date) {
            if (($date['type'] ?? null) === 'FLEXIBLE') {
                $reasons[] = 'Esnek tarih aralığıyla uçuş araması henüz yapılamıyor; aralıktan bir tarih seçilmez.';
                break;
            }
        }

        return $reasons === [] ? null : implode(' ', $reasons);
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'trip_request_id' => $this->tripRequestId,
            'origin_place' => $this->originPlace,
            'destination_place' => $this->destinationPlace,
            'journey_type' => $this->journeyType,
            'outbound_date' => $this->outboundDate,
        ] + ($this->returnDate === null ? [] : ['return_date' => $this->returnDate]) + [
            'traveler_count' => $this->travelerCount,
            'passenger_composition' => $this->passengerComposition,
        ];
    }
}
