<?php

namespace App\Modules\Api\Contracts;

final readonly class TripRequestView
{
    /**
     * Places are SCH-LocationRef v3.8; aviation_identity is present only for server-selected flight locations.
     * v3.9: journeyType/outboundDate/returnDate are the canonical journey dates; dateWindow is legacy (v3.2)
     * data of records created before v3.9 and is never reinterpreted.
     *
     * @param  array{kind: string, value: string, iana_timezone: string, aviation_identity?: array{type: string, code: string}}  $originPlace
     * @param  array{kind: string, value: string, iana_timezone: string, aviation_identity?: array{type: string, code: string}}  $destinationPlace
     * @param  array<string, mixed>|null  $dateWindow
     * @param  array<string, mixed>|null  $preferences
     * @param  array<string, string>|null  $outboundDate
     * @param  array<string, string>|null  $returnDate
     */
    public function __construct(
        public string $status,
        public array $originPlace,
        public array $destinationPlace,
        public ?array $dateWindow,
        public int $travelerCount,
        public ?array $preferences,
        public ?string $journeyType = null,
        public ?array $outboundDate = null,
        public ?array $returnDate = null,
        public ?array $passengerComposition = null,
        public ?int $roomCount = null,
    ) {}

    /** A pre-v3.9 record: it has a legacy date_window and no journey; its dates have no known direction. */
    public function isLegacyDateModel(): bool
    {
        return $this->dateWindow !== null && $this->journeyType === null;
    }

    /**
     * Public /api/v1 SCH-TripRequest v3.9 representation. Places stay SCH-LocationRef v3.2: the internal
     * aviation_identity (v3.8) is omitted here.
     *
     * @return array<string, mixed>
     */
    public function toContract(): array
    {
        $public = fn (array $place): array => array_diff_key($place, ['aviation_identity' => true, 'coordinates' => true]);

        return [
            'status' => $this->status,
            'origin_place' => $public($this->originPlace),
            'destination_place' => $public($this->destinationPlace),
            'journey_type' => $this->journeyType,
            'outbound_date' => $this->outboundDate,
            'return_date' => $this->returnDate,
            'date_window' => $this->dateWindow,
            'traveler_count' => $this->travelerCount,
            'passenger_composition' => $this->passengerComposition,
            'preferences' => $this->preferences,
            // v3.14: optional; omitted for records without an explicit room count (legacy/API requests without it).
        ] + ($this->roomCount === null ? [] : ['room_count' => $this->roomCount]);
    }
}
