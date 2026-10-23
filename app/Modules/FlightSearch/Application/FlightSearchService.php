<?php

namespace App\Modules\FlightSearch\Application;

use App\Modules\FlightSearch\Domain\FlightSearchJobRecord;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use DateTimeImmutable;
use Illuminate\Support\Str;

final readonly class FlightSearchService
{
    public function __construct(private FlightSearchJobRepository $jobs, private FlightSearchDispatcher $dispatcher) {}

    /** @throws IdempotencyKeyConflict when the key already belongs to a search of another TripRequest */
    public function start(string $idempotencyKey, string $tripRequestId): FlightSearchJobRecord
    {
        $existing = $this->jobs->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->sameRequest($existing, $tripRequestId);
        }
        $record = new FlightSearchJobRecord((string) Str::uuid(), $idempotencyKey, 'QUEUED', null, null, null, null, $tripRequestId);
        if (! $this->jobs->insertIfAbsent($record)) {
            return $this->sameRequest($this->jobs->findByIdempotencyKey($idempotencyKey), $tripRequestId);
        }
        $this->dispatcher->dispatch($record->searchId);

        return $record;
    }

    private function sameRequest(FlightSearchJobRecord $existing, string $tripRequestId): FlightSearchJobRecord
    {
        if ($existing->tripRequestId !== $tripRequestId) {
            throw new IdempotencyKeyConflict;
        }

        return $existing;
    }

    /** Read-only lookup without the expiry transition, so a poll is authorized before it can change the job. */
    public function find(string $searchId): ?FlightSearchJobRecord
    {
        return $this->jobs->find($searchId);
    }

    public function poll(string $searchId, ?DateTimeImmutable $now = null): ?FlightSearchJobRecord
    {
        $record = $this->jobs->find($searchId);
        if ($record === null || $record->isTerminal()) {
            return $record;
        }
        $now ??= new DateTimeImmutable('now');
        if ($record->expiresAt !== null && $record->expiresAt <= $now) {
            $this->jobs->expire($searchId);

            return $this->jobs->find($searchId);
        }

        return $record;
    }
}
