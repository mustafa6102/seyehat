<?php

namespace App\Modules\Traveler\Contracts;

use App\Modules\Traveler\Domain\Traveler;

interface TravelerService
{
    /**
     * Legacy OP-createTraveler path: it performs no authorization and keeps the given id, so it must never receive
     * unauthorized caller input. New creation goes through TripTravelerEnrollment.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws TravelerStorageClosed
     */
    public function create(array $input): Traveler;

    public function get(string $id): ?Traveler;
}
