<?php

namespace App\Modules\Event\Infrastructure\Persistence;

use App\Modules\Event\Contracts\EventReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * EVT-001 (v3.22): canonical event storage. insertOrIgnore + update matches the POI pattern (DatabasePoiRepository).
 * Only the SCH-Event array (with event_id) is stored; raw Ticketmaster responses are never persisted.
 */
final readonly class DatabaseEventRepository implements EventReader
{
    private const TABLE = 'events';

    public function __construct(private ConnectionInterface $database) {}

    /** @return array<string, mixed>|null SCH-Event with event_id */
    public function find(string $eventId): ?array
    {
        $row = $this->database->table(self::TABLE)->where('event_id', $eventId)->first();

        return $row === null ? null : json_decode($row->event_json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Idempotent upsert keyed by (provider_id, provider_event_id). Returns the stored SCH-Event (with event_id).
     *
     * @param  array<string, mixed>  $event  SCH-Event without event_id
     * @return array<string, mixed>  SCH-Event with event_id
     */
    public function save(array $event): array
    {
        $providerId = $event['provider_ref']['provider_id'] ?? '';
        $providerEventId = $event['provider_ref']['provider_event_id'] ?? '';

        $existing = $this->database->table(self::TABLE)
            ->where('provider_id', $providerId)
            ->where('provider_event_id', $providerEventId)
            ->first();

        if ($existing !== null) {
            $stored = json_decode($existing->event_json, true, 512, JSON_THROW_ON_ERROR);
            // Refresh evidence facts when the provider re-delivers the same event.
            $merged = array_merge($stored, $event, ['event_id' => $stored['event_id']]);
            $this->database->table(self::TABLE)
                ->where('event_id', $stored['event_id'])
                ->update(['event_json' => json_encode($merged, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $merged;
        }

        $eventId = (string) Str::uuid();
        $full = array_merge(['event_id' => $eventId], $event);
        $this->database->table(self::TABLE)->insertOrIgnore([
            'event_id' => $eventId,
            'provider_id' => $providerId,
            'provider_event_id' => $providerEventId,
            'event_json' => json_encode($full, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // If insertOrIgnore lost a race, fetch the winner's row.
        $winner = $this->database->table(self::TABLE)
            ->where('provider_id', $providerId)
            ->where('provider_event_id', $providerEventId)
            ->first();

        return $winner !== null
            ? json_decode($winner->event_json, true, 512, JSON_THROW_ON_ERROR)
            : $full;
    }
}
