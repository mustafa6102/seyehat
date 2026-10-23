<?php

namespace App\Modules\Accommodation\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;

/**
 * SCH-AccommodationSearchJob v3.14 storage. Only the canonical contract result (AccommodationOffer list) is stored -
 * never a raw provider response. Terminal states are immutable (same rule as flight_search_jobs).
 */
final readonly class DatabaseAccommodationSearchJobRepository
{
    private const TABLE = 'accommodation_search_jobs';

    private const TERMINAL = ['COMPLETED', 'FAILED', 'EXPIRED'];

    public function __construct(private ConnectionInterface $database) {}

    /** @return array<string, mixed>|null SCH-AccommodationSearchJob */
    public function find(string $searchId): ?array
    {
        return $this->contract($this->database->table(self::TABLE)->where('search_id', $searchId)->first());
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->contract($this->database->table(self::TABLE)->where('idempotency_key', $key)->first());
    }

    public function insertIfAbsent(string $searchId, string $idempotencyKey, string $tripRequestId): bool
    {
        return $this->database->table(self::TABLE)->insertOrIgnore([
            'search_id' => $searchId, 'idempotency_key' => $idempotencyKey, 'trip_request_id' => $tripRequestId,
            'status' => 'QUEUED', 'result_json' => null, 'error_code' => null, 'error_message_tr' => null,
            'expires_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]) === 1;
    }

    public function isSettled(string $searchId): bool
    {
        $status = $this->database->table(self::TABLE)->where('search_id', $searchId)->value('status');

        return $status === null || in_array($status, self::TERMINAL, true);
    }

    public function markRunning(string $searchId): void
    {
        $this->update($searchId, ['status' => 'RUNNING']);
    }

    /** @param  list<array<string, mixed>>  $offers */
    public function complete(string $searchId, array $offers): void
    {
        $this->update($searchId, ['status' => 'COMPLETED', 'result_json' => json_encode(['offers' => $offers], JSON_THROW_ON_ERROR), 'error_code' => null, 'error_message_tr' => null]);
    }

    public function fail(string $searchId, ?string $code, string $messageTr): void
    {
        $this->update($searchId, ['status' => 'FAILED', 'result_json' => null, 'error_code' => $code, 'error_message_tr' => $messageTr]);
    }

    /** @param  array<string, mixed>  $values */
    private function update(string $searchId, array $values): void
    {
        $this->database->table(self::TABLE)
            ->where('search_id', $searchId)
            ->whereNotIn('status', self::TERMINAL)
            ->update([...$values, 'updated_at' => now()]);
    }

    /** @return array<string, mixed>|null */
    private function contract(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'search_id' => (string) $row->search_id,
            'trip_request_id' => (string) $row->trip_request_id,
            'status' => $row->status,
            'result' => $row->result_json === null ? null : json_decode($row->result_json, true, flags: JSON_THROW_ON_ERROR),
            'error' => $row->status === 'FAILED' ? ['code' => $row->error_code, 'message_tr' => (string) $row->error_message_tr] : null,
            'expires_at' => $row->expires_at === null ? null : (new \DateTimeImmutable($row->expires_at, new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }
}
