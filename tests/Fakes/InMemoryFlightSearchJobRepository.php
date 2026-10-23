<?php

namespace Tests\Fakes;

use App\Modules\FlightSearch\Domain\FlightSearchJobRecord;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;

final class InMemoryFlightSearchJobRepository implements FlightSearchJobRepository
{
    /** @var array<string, FlightSearchJobRecord> */
    private array $records = [];

    public int $mutations = 0;

    public function find(string $searchId): ?FlightSearchJobRecord
    {
        return $this->records[$searchId] ?? null;
    }

    public function findByIdempotencyKey(string $key): ?FlightSearchJobRecord
    {
        foreach ($this->records as $record) {
            if ($record->idempotencyKey === $key) {
                return $record;
            }
        }

        return null;
    }

    public function insertIfAbsent(FlightSearchJobRecord $record): bool
    {
        if ($this->findByIdempotencyKey($record->idempotencyKey) !== null) {
            return false;
        }
        $this->save($record);

        return true;
    }

    public function save(FlightSearchJobRecord $record): void
    {
        $this->records[$record->searchId] = $record;
    }

    public function markRunning(string $searchId): void
    {
        $this->mutate($searchId, fn (FlightSearchJobRecord $record): FlightSearchJobRecord => $record->withStatus('RUNNING'));
    }

    public function recordPartial(string $searchId, array $result): void
    {
        $this->mutate($searchId, fn (FlightSearchJobRecord $record): FlightSearchJobRecord => $record->withResult('PARTIAL', $result));
    }

    public function recordPartialFailure(string $searchId, array $result, ?string $code, string $messageTr): void
    {
        $this->mutate($searchId, fn (FlightSearchJobRecord $record): FlightSearchJobRecord => $record->withPartialFailure($result, $code, $messageTr));
    }

    public function complete(string $searchId, array $result): void
    {
        $this->mutate($searchId, fn (FlightSearchJobRecord $record): FlightSearchJobRecord => $record->withResult('COMPLETED', $result));
    }

    public function fail(string $searchId, ?string $code, string $messageTr): void
    {
        $this->mutate($searchId, fn (FlightSearchJobRecord $record): FlightSearchJobRecord => $record->withFailure($code, $messageTr));
    }

    public function expire(string $searchId): void
    {
        $this->mutate($searchId, fn (FlightSearchJobRecord $record): FlightSearchJobRecord => $record->withExpiry());
    }

    /** Mirrors the database repository: terminal records are never mutated again. */
    private function mutate(string $searchId, callable $change): void
    {
        $record = $this->find($searchId);
        if ($record === null || $record->isTerminal()) {
            return;
        }
        $this->records[$searchId] = $change($record);
        $this->mutations++;
    }
}
