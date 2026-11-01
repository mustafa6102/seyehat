<?php

namespace App\Modules\Api\Domain\TripRequest;

use App\Modules\Api\Contracts\InvalidTripRequest;
use App\Modules\Api\Contracts\PassengerComposition;
use App\Modules\Api\Contracts\TravelDate;
use App\Modules\Api\Contracts\TripJourney;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Authorization\Contracts\PrincipalRef;

final readonly class TripRequest
{
    /**
     * v3.9: journeyType/outboundDate/returnDate are the canonical writable date model. dateWindow is legacy
     * (v3.2) data of records created before v3.9; it is only read back, never written or reinterpreted.
     *
     * @param  array<string, mixed>|null  $dateWindow
     * @param  array<string, mixed>|null  $preferences
     * @param  array<string, string>|null  $outboundDate
     * @param  array<string, string>|null  $returnDate
     */
    public function __construct(
        public ?string $id,
        public string $status,
        public ?LocationRef $originPlace,
        public ?LocationRef $destinationPlace,
        public ?array $dateWindow,
        public ?int $travelerCount,
        public ?array $preferences,
        public ?string $journeyType = null,
        public ?array $outboundDate = null,
        public ?array $returnDate = null,
        public ?array $passengerComposition = null,
        public ?int $roomCount = null,
        // RULE-PLT-AUTH-001-04: SCH-PrincipalRef of the creator; null only for legacy (ownerless, never claimable) records.
        public ?string $ownerIssuer = null,
        public ?string $ownerSubject = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidTripRequest when present fields violate SCH-TripRequest or RULE-TRIP-001-01/04/05/06
     */
    public static function draft(array $input): self
    {
        $journeyType = $input['journey_type'] ?? null;
        $outboundDate = $input['outbound_date'] ?? null;
        $returnDate = $input['return_date'] ?? null;
        $invalid = TripJourney::invalidFields($journeyType, $outboundDate, $returnDate);
        foreach (['origin_place', 'destination_place'] as $place) {
            $kind = $input[$place]['kind'] ?? null;
            if (is_string($kind) && ! in_array($kind, LocationRef::KINDS, true)) {
                $invalid[] = $place;
            }
        }
        if ($invalid !== []) {
            throw new InvalidTripRequest($invalid);
        }
        // A non-integer count would otherwise be coerced after skipping the RULE-TRIP-001-06 total check.
        $travelerCount = $input['traveler_count'] ?? null;
        if ($travelerCount !== null && ! is_int($travelerCount)) {
            throw new InvalidTripRequest(['traveler_count']);
        }
        $compositionInput = $input['passenger_composition'] ?? null;
        $composition = PassengerComposition::fromContract($compositionInput, $travelerCount);
        if ($compositionInput !== null && $composition === null) {
            throw new InvalidTripRequest(['passenger_composition']);
        }
        // v3.14: explicit user input for accommodation search; never inferred when absent.
        $roomCount = $input['room_count'] ?? null;
        if ($roomCount !== null && (! is_int($roomCount) || $roomCount < 1)) {
            throw new InvalidTripRequest(['room_count']);
        }

        return new self(
            id: null,
            status: 'DRAFT',
            originPlace: LocationRef::fromNullable($input['origin_place'] ?? null),
            destinationPlace: LocationRef::fromNullable($input['destination_place'] ?? null),
            dateWindow: null,
            travelerCount: $travelerCount,
            preferences: self::canonicalPreferences($input['preferences'] ?? null),
            journeyType: $journeyType,
            outboundDate: TravelDate::fromContract($outboundDate)?->toContract(),
            returnDate: TravelDate::fromContract($returnDate)?->toContract(),
            passengerComposition: $composition?->toContract(),
            roomCount: $roomCount,
        );
    }

    /**
     * SCH-TripRequest.preferences is an object or null: a list is rejected, and a blank pace or empty object means "no preference".
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidTripRequest
     */
    private static function canonicalPreferences(mixed $preferences): ?array
    {
        if ($preferences === null || $preferences === []) {
            return null;
        }
        $pace = is_array($preferences) ? ($preferences['pace_preference'] ?? null) : null;
        if (! is_array($preferences) || array_is_list($preferences) || ($pace !== null && ! is_string($pace))) {
            throw new InvalidTripRequest(['preferences']);
        }
        unset($preferences['pace_preference']);
        $pace = trim((string) $pace);

        return ($pace === '' ? $preferences : ['pace_preference' => $pace] + $preferences) ?: null;
    }

    /** @return list<string> */
    public function missingRequiredFields(): array
    {
        $missing = [];

        if ($this->originPlace === null) {
            $missing[] = 'origin_place';
        }
        if ($this->destinationPlace === null) {
            $missing[] = 'destination_place';
        }
        array_push($missing, ...TripJourney::missingFields($this->journeyType, $this->outboundDate, $this->returnDate));
        if ($this->travelerCount === null || $this->travelerCount < 1) {
            $missing[] = 'traveler_count';
        }

        return $missing;
    }

    public function normalize(): self
    {
        if ($this->status === 'SEARCHABLE' || $this->missingRequiredFields() !== []) {
            return $this;
        }

        return $this->with(status: 'SEARCHABLE');
    }

    public function withId(string $id): self
    {
        return $this->with(id: $id);
    }

    /** Owner of a new (unsaved) TripRequest; an existing owner is never replaced (RULE-PLT-AUTH-001-04). */
    public function ownedBy(PrincipalRef $owner): self
    {
        if ($this->id !== null || $this->ownerIssuer !== null || $this->ownerSubject !== null) {
            throw new \LogicException('TripRequest owner is immutable.');
        }

        return new self(
            $this->id, $this->status, $this->originPlace, $this->destinationPlace, $this->dateWindow, $this->travelerCount,
            $this->preferences, $this->journeyType, $this->outboundDate, $this->returnDate, $this->passengerComposition,
            $this->roomCount, $owner->issuer, $owner->subject,
        );
    }

    /** Null for a legacy record without owner. Authorization data only; never part of TripRequestView. */
    public function owner(): ?PrincipalRef
    {
        return $this->ownerIssuer !== null && $this->ownerSubject !== null
            ? new PrincipalRef($this->ownerIssuer, $this->ownerSubject)
            : null;
    }

    public function toView(): TripRequestView
    {
        if ($this->originPlace === null || $this->destinationPlace === null || $this->travelerCount === null) {
            throw new \LogicException('Incomplete TripRequest cannot be represented by SCH-TripRequest.');
        }

        return new TripRequestView(
            $this->status,
            $this->originPlace->toContract(),
            $this->destinationPlace->toContract(),
            $this->dateWindow,
            $this->travelerCount,
            $this->preferences,
            $this->journeyType,
            $this->outboundDate,
            $this->returnDate,
            $this->passengerComposition,
            $this->roomCount,
        );
    }

    private function with(?string $id = null, ?string $status = null): self
    {
        return new self(
            $id ?? $this->id,
            $status ?? $this->status,
            $this->originPlace,
            $this->destinationPlace,
            $this->dateWindow,
            $this->travelerCount,
            $this->preferences,
            $this->journeyType,
            $this->outboundDate,
            $this->returnDate,
            $this->passengerComposition,
            $this->roomCount,
            $this->ownerIssuer,
            $this->ownerSubject,
        );
    }
}
