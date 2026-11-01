<?php

namespace App\Modules\Api\Infrastructure\Persistence;

use App\Modules\Api\Domain\TripRequest\LocationRef;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Domain\TripRequest\TripRequestRepository;
use App\Modules\Authorization\Contracts\PrincipalRef;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseTripRequestRepository implements TripRequestRepository
{
    public function __construct(private ConnectionInterface $database) {}

    public function save(TripRequest $tripRequest): TripRequest
    {
        $values = [
            'status' => $tripRequest->status,
            'origin_place' => $this->encode($tripRequest->originPlace?->toContract()),
            'destination_place' => $this->encode($tripRequest->destinationPlace?->toContract()),
            // Legacy (v3.2) value is written back unchanged; new records never have one.
            'date_window' => $this->encode($tripRequest->dateWindow),
            'journey_type' => $tripRequest->journeyType,
            'outbound_date' => $this->encode($tripRequest->outboundDate),
            'return_date' => $this->encode($tripRequest->returnDate),
            'traveler_count' => $tripRequest->travelerCount,
            'passenger_composition' => $this->encode($tripRequest->passengerComposition),
            'preferences' => $this->encode($tripRequest->preferences),
        ];
        // v3.14 room_count is written only when present, so records without it never touch the (manually added) column.
        if ($tripRequest->roomCount !== null) {
            $values['room_count'] = $tripRequest->roomCount;
        }

        if ($tripRequest->id === null) {
            // RULE-PLT-AUTH-001-04: the owner is written once at creation and never on update (immutable).
            if ($tripRequest->ownerIssuer !== null || $tripRequest->ownerSubject !== null) {
                $values['owner_issuer'] = $tripRequest->ownerIssuer;
                $values['owner_subject'] = $tripRequest->ownerSubject;
            }
            $id = (string) $this->database->table('trip_requests')->insertGetId($values);

            return $tripRequest->withId($id);
        }

        $this->database->table('trip_requests')->where('id', $tripRequest->id)->update($values);

        return $tripRequest;
    }

    public function find(string $id): ?TripRequest
    {
        // SCH-TripRequest ids are opaque strings in the contract; this store issues auto-increment integers. A non-digit
        // id can never match, and is rejected here so MariaDB never coerces e.g. "12abc" to 12.
        if (! ctype_digit($id)) {
            return null;
        }
        $record = $this->database->table('trip_requests')->where('id', $id)->first();

        if ($record === null) {
            return null;
        }

        return $this->hydrate($record);
    }

    public function ownedBy(PrincipalRef $owner): array
    {
        return $this->database->table('trip_requests')
            ->where('owner_issuer', $owner->issuer)
            ->where('owner_subject', $owner->subject)
            ->where('status', 'SEARCHABLE')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $record): TripRequest => $this->hydrate($record))
            ->all();
    }

    private function hydrate(object $record): TripRequest
    {
        return new TripRequest(
            id: (string) $record->id,
            status: $record->status,
            originPlace: LocationRef::fromNullable($this->decode($record->origin_place)),
            destinationPlace: LocationRef::fromNullable($this->decode($record->destination_place)),
            dateWindow: $this->decode($record->date_window),
            travelerCount: $record->traveler_count === null ? null : (int) $record->traveler_count,
            preferences: $this->decode($record->preferences),
            journeyType: $record->journey_type,
            outboundDate: $this->decode($record->outbound_date),
            returnDate: $this->decode($record->return_date),
            passengerComposition: $this->decode($record->passenger_composition ?? null),
            roomCount: isset($record->room_count) ? (int) $record->room_count : null,
            ownerIssuer: isset($record->owner_issuer) ? (string) $record->owner_issuer : null,
            ownerSubject: isset($record->owner_subject) ? (string) $record->owner_subject : null,
        );
    }

    /** @param array<string, mixed>|null $value */
    private function encode(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|null */
    private function decode(?string $value): ?array
    {
        return $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}
