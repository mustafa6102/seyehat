<?php

namespace App\Modules\HowTo\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;

/** Durable replay ledger for HOW refresh commands; never creates PlanRevision or outbox records. */
final readonly class DatabaseHowOperationRepository
{
    private const TABLE = 'how_operation_idempotency';

    public function __construct(private ConnectionInterface $database) {}

    /** @return array<string, mixed>|null */
    public function find(string $scopeHash, string $key): ?array
    {
        $row = $this->database->table(self::TABLE)->where('scope_hash', $scopeHash)->where('idempotency_key', $key)->first();
        if ($row === null) {
            return null;
        }

        return [
            'request_fingerprint' => (string) $row->request_fingerprint,
            'status' => (string) $row->status,
            'response_status' => $row->response_status === null ? null : (int) $row->response_status,
            'response_body' => $row->response_json === null ? null : json_decode((string) $row->response_json, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    public function claim(string $scopeHash, string $principalHash, string $tripRequestId, string $itemId, int $version, string $key, string $fingerprint): bool
    {
        return $this->database->table(self::TABLE)->insertOrIgnore([
            'scope_hash' => $scopeHash,
            'principal_hash' => $principalHash,
            'operation' => 'refreshTripPlanItemGuide',
            'trip_request_id' => $tripRequestId,
            'item_id' => $itemId,
            'base_revision_version' => $version,
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
            'status' => 'IN_PROGRESS',
            'response_status' => null,
            'response_json' => null,
            'created_at' => now(),
            'completed_at' => null,
        ]) === 1;
    }

    /** @param array<string, mixed> $body */
    public function complete(string $scopeHash, string $key, int $status, array $body): void
    {
        $this->database->table(self::TABLE)->where('scope_hash', $scopeHash)->where('idempotency_key', $key)->update([
            'status' => 'COMPLETED',
            'response_status' => $status,
            'response_json' => json_encode($body, JSON_THROW_ON_ERROR),
            'completed_at' => now(),
        ]);
    }
}
