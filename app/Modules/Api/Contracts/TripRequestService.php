<?php

namespace App\Modules\Api\Contracts;

use App\Modules\Authorization\Contracts\PrincipalRef;

interface TripRequestService
{
    /**
     * @param  array<string, mixed>  $input
     * @param  PrincipalRef  $owner  authenticated creator (RULE-PLT-AUTH-001-04); never taken from $input
     *
     * @throws InvalidTripRequest journey/date fields violate TRIP-001 v3.9
     * @throws IncompleteTripRequest required fields are missing (stored as DRAFT)
     */
    public function create(array $input, PrincipalRef $owner): CreatedTripRequest;

    public function get(string $id): ?TripRequestView;

    /** @return list<array{id: string, trip_request: TripRequestView}> */
    public function ownedBy(PrincipalRef $owner): array;
}
