<?php

namespace App\Modules\FlightSearch\Infrastructure\Persistence;

use App\Modules\FlightSearch\Domain\FlightSearchJobRecord;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseFlightSearchJobRepository implements FlightSearchJobRepository
{
    public function __construct(private ConnectionInterface $database) {}

    public function find(string $searchId): ?FlightSearchJobRecord
    {
        return $this->hydrate($this->database->table('flight_search_jobs')->where('search_id', $searchId)->first());
    }

    public function findByIdempotencyKey(string $key): ?FlightSearchJobRecord
    {
        return $this->hydrate($this->database->table('flight_search_jobs')->where('idempotency_key', $key)->first());
    }

    public function insertIfAbsent(FlightSearchJobRecord $record): bool
    {
        return $this->database->table('flight_search_jobs')->insertOrIgnore([
            'search_id' => $record->searchId, 'idempotency_key' => $record->idempotencyKey, 'trip_request_id' => $record->tripRequestId, 'status' => $record->status,
            'result_json' => null, 'error_code' => null, 'error_message_tr' => null,
            'expires_at' => $record->expiresAt?->format('Y-m-d H:i:s.u'), 'created_at' => now(), 'updated_at' => now(),
        ]) === 1;
    }

    public function save(FlightSearchJobRecord $record): void
    {
        $this->database->table('flight_search_jobs')->insert([
            'search_id' => $record->searchId, 'idempotency_key' => $record->idempotencyKey, 'trip_request_id' => $record->tripRequestId, 'status' => $record->status,
            'result_json' => $record->result === null ? null : json_encode($record->result, JSON_THROW_ON_ERROR),
            'error_code' => $record->errorCode, 'error_message_tr' => $record->errorMessageTr,
            'expires_at' => $record->expiresAt?->format('Y-m-d H:i:s.u'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function markRunning(string $searchId): void
    {
        $this->update($searchId, ['status' => 'RUNNING']);
    }

    public function recordPartial(string $searchId, array $result): void
    {
        $this->update($searchId, ['status' => 'PARTIAL', 'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'error_code' => null, 'error_message_tr' => null]);
    }

    public function recordPartialFailure(string $searchId, array $result, ?string $code, string $messageTr): void
    {
        $this->update($searchId, ['status' => 'PARTIAL', 'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'error_code' => $code, 'error_message_tr' => $messageTr]);
    }

    public function complete(string $searchId, array $result): void
    {
        $this->update($searchId, ['status' => 'COMPLETED', 'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'error_code' => null, 'error_message_tr' => null]);
    }

    public function fail(string $searchId, ?string $code, string $messageTr): void
    {
        $this->update($searchId, ['status' => 'FAILED', 'result_json' => null, 'error_code' => $code, 'error_message_tr' => $messageTr]);
    }

    public function expire(string $searchId): void
    {
        $this->update($searchId, ['status' => 'EXPIRED', 'result_json' => null, 'error_code' => null, 'error_message_tr' => 'Arama işinin geçerlilik süresi doldu.']);
    }

    private function update(string $searchId, array $values): void
    {
        // Terminal lifecycle states are immutable, so duplicate delivery or DLQ replay cannot mutate them again.
        $this->database->table('flight_search_jobs')
            ->where('search_id', $searchId)
            ->whereNotIn('status', ['COMPLETED', 'FAILED', 'EXPIRED'])
            ->update([...$values, 'updated_at' => now()]);
    }

    private function hydrate(?object $row): ?FlightSearchJobRecord
    {
        if ($row === null) {
            return null;
        }

        return new FlightSearchJobRecord($row->search_id, $row->idempotency_key, $row->status, $row->result_json === null ? null : json_decode($row->result_json, true, flags: JSON_THROW_ON_ERROR), $row->error_code, $row->error_message_tr, $row->expires_at === null ? null : new DateTimeImmutable($row->expires_at), $row->trip_request_id ?? null);
    }
}
