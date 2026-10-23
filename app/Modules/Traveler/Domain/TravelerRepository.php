<?php

namespace App\Modules\Traveler\Domain;

interface TravelerRepository
{
    public function save(Traveler $traveler): Traveler;

    public function find(string $id): ?Traveler;
}
