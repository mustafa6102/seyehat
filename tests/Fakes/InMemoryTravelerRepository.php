<?php

namespace Tests\Fakes;

use App\Modules\Traveler\Domain\Traveler;
use App\Modules\Traveler\Domain\TravelerRepository;

final class InMemoryTravelerRepository implements TravelerRepository
{
    /** @var array<string, Traveler> */
    private array $travelers = [];

    public function save(Traveler $traveler): Traveler
    {
        $this->travelers[$traveler->id] = $traveler;

        return $traveler;
    }

    public function find(string $id): ?Traveler
    {
        return $this->travelers[$id] ?? null;
    }
}
