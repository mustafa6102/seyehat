<?php

namespace App\Modules\Api\Domain\TripRequest;

interface TripRequestRepository
{
    public function save(TripRequest $tripRequest): TripRequest;

    public function find(string $id): ?TripRequest;
}
