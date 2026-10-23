<?php

namespace App\Modules\TripBuilder\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * SCH-Trip / SCH-PlanRevision storage (v3.16, OD-021, OD-027). trips.trip_request_id is unique (one Trip per TripRequest);
 * plan_revisions rows are append-only except the CURRENT -> SUPERSEDED status change; (trip_id, version) and
 * (trip_id, idempotency_key) are unique. Revision content (items incl. poi_snapshot, change) is written once.
 */
final readonly class DatabaseTripPlanRepository
{
    public function __construct(private ConnectionInterface $database) {}

    /** @return array{trip_id: string, trip_request_id: string, created_at: string}|null */
    public function tripFor(string $tripRequestId, bool $lock = false): ?array
    {
        $query = $this->database->table('trips')->where('trip_request_id', $tripRequestId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : [
            'trip_id' => (string) $row->trip_id,
            'trip_request_id' => (string) $row->trip_request_id,
            'created_at' => $this->timestamp($row->created_at),
        ];
    }

    /** @return array{trip_id: string, trip_request_id: string, created_at: string} */
    public function createTrip(string $tripRequestId): array
    {
        $trip = ['trip_id' => (string) Str::uuid(), 'trip_request_id' => $tripRequestId, 'created_at' => $this->now()];
        $this->database->table('trips')->insert($trip);

        return ['created_at' => $this->timestamp($trip['created_at'])] + $trip;
    }

    /** @return array<string, mixed>|null SCH-PlanRevision */
    public function currentRevision(string $tripId, bool $lock = false): ?array
    {
        $query = $this->database->table('plan_revisions')->where('trip_id', $tripId)->where('status', 'CURRENT');

        return $this->revision(($lock ? $query->lockForUpdate() : $query)->first());
    }

    /** @return array{revision: array<string, mixed>, fingerprint: string}|null */
    public function revisionByIdempotencyKey(string $tripId, string $key): ?array
    {
        $row = $this->database->table('plan_revisions')->where('trip_id', $tripId)->where('idempotency_key', $key)->first();

        return $row === null ? null : ['revision' => $this->revision($row), 'fingerprint' => (string) $row->request_fingerprint];
    }

    public function supersede(string $tripId, int $version): void
    {
        $this->database->table('plan_revisions')->where('trip_id', $tripId)->where('version', $version)
            ->where('status', 'CURRENT')->update(['status' => 'SUPERSEDED']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $change
     * @return array<string, mixed> SCH-PlanRevision
     */
    public function insertRevision(string $tripId, int $version, array $items, array $change, string $idempotencyKey, string $fingerprint): array
    {
        $row = [
            'trip_id' => $tripId,
            'version' => $version,
            'status' => 'CURRENT',
            'items_json' => json_encode($items, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'change_json' => json_encode($change, JSON_THROW_ON_ERROR),
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'created_at' => $this->now(),
        ];
        $this->database->table('plan_revisions')->insert($row);

        return $this->revision((object) $row);
    }

    /** @return array<string, mixed>|null */
    private function revision(?object $row): ?array
    {
        return $row === null ? null : [
            'trip_id' => (string) $row->trip_id,
            'version' => (int) $row->version,
            'status' => (string) $row->status,
            'items' => json_decode((string) $row->items_json, true, flags: JSON_THROW_ON_ERROR),
            'change' => json_decode((string) $row->change_json, true, flags: JSON_THROW_ON_ERROR),
            'created_at' => $this->timestamp($row->created_at),
        ];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function timestamp(mixed $value): string
    {
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
