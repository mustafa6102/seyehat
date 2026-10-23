<?php

namespace App\Modules\Traveler\Contracts;

use App\Modules\Traveler\Domain\Traveler;

interface TravelerService
{
    /** @param array<string, mixed> $input */
    public function create(array $input): Traveler;

    public function get(string $id): ?Traveler;
}
