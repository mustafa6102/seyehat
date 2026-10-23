<?php

namespace Tests\Fakes;

use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Domain\TripRequest\TripRequestRepository;

final class InMemoryTripRequestRepository implements TripRequestRepository
{
    /** @var array<string, TripRequest> */
    private array $tripRequests = [];

    private int $nextId = 1;

    public function save(TripRequest $tripRequest): TripRequest
    {
        $stored = $tripRequest->withId($tripRequest->id ?? (string) $this->nextId++);
        $this->tripRequests[$stored->id] = $stored;

        return $stored;
    }

    public function find(string $id): ?TripRequest
    {
        return $this->tripRequests[$id] ?? null;
    }

    /** @return list<TripRequest> */
    public function all(): array
    {
        return array_values($this->tripRequests);
    }
}
