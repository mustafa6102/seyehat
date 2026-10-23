<?php

namespace App\Modules\FlightSearch\Domain;

interface FlightSearchJobRepository
{
    public function find(string $searchId): ?FlightSearchJobRecord;

    public function findByIdempotencyKey(string $key): ?FlightSearchJobRecord;

    public function insertIfAbsent(FlightSearchJobRecord $record): bool;

    public function save(FlightSearchJobRecord $record): void;

    public function markRunning(string $searchId): void;

    /** @param array{offers: array<int, mixed>} $result */
    public function recordPartial(string $searchId, array $result): void;

    /**
     * Final PARTIAL snapshot labelled with the provider degradation reason (PLT-REL-001).
     *
     * @param  array{offers: array<int, mixed>}  $result
     */
    public function recordPartialFailure(string $searchId, array $result, ?string $code, string $messageTr): void;

    /** @param array{offers: array<int, mixed>} $result */
    public function complete(string $searchId, array $result): void;

    public function fail(string $searchId, ?string $code, string $messageTr): void;

    public function expire(string $searchId): void;
}
