<?php

namespace App\Modules\Api\Domain\TripRequest;

use App\Modules\Authorization\Contracts\PrincipalRef;

interface TripRequestRepository
{
    public function save(TripRequest $tripRequest): TripRequest;

    public function find(string $id): ?TripRequest;

    /** @return list<TripRequest> */
    public function ownedBy(PrincipalRef $owner): array;
}
