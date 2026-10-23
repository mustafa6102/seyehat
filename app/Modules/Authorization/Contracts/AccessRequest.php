<?php

namespace App\Modules\Authorization\Contracts;

/**
 * One authorization question, always evaluated in trip and traveler scope (FR-PLT-AUTH-001-01).
 * Identity (subject) comes from AuthN, which is separate and pending ADR-010.
 */
final readonly class AccessRequest
{
    /**
     * @param  list<TripRole>  $rolesInTrip  roles the subject holds in $tripId
     * @param  list<string>  $tripTravelerIds  travelers that belong to $tripId
     * @param  list<string>  $ownTravelerIds  traveler records that represent the subject
     */
    public function __construct(
        public string $subjectId,
        public array $rolesInTrip,
        public string $resource,
        public string $action,
        public ?string $tripId = null,
        public ?string $travelerId = null,
        public array $tripTravelerIds = [],
        public array $ownTravelerIds = [],
        public bool $travelerIsMinor = false,
        public ?string $systemWorkflow = null,
    ) {}
}
