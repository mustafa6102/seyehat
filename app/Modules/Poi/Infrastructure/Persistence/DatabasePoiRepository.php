<?php

namespace App\Modules\Poi\Infrastructure\Persistence;

use App\Modules\Poi\Contracts\PoiReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Canonical SCH-Poi store for OP-getPoi / OP-verifyPoi (OD-025 storage: canonical fields and identifiers only, never a raw
 * Overpass response). One row per provider element; a later discovery or verification replaces the canonical facts and keeps
 * the poi_id. No retention period is applied: OD-013 is OPEN, same as the existing search-job tables.
 */
final readonly class DatabasePoiRepository implements PoiReader
{
    private const TABLE = 'pois';

    public function __construct(private ConnectionInterface $database) {}

    /** @return array<string, mixed>|null SCH-Poi */
    public function find(string $poiId): ?array
    {
        $json = $this->database->table(self::TABLE)->where('poi_id', $poiId)->value('poi_json');

        return is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;
    }

    /**
     * Stores a mapped POI (without poi_id) under the element's existing poi_id, or a new one.
     *
     * @param  array<string, mixed>  $poi  SCH-Poi without poi_id
     * @return array<string, mixed> SCH-Poi
     */
    public function save(array $poi): array
    {
        $key = ['provider_id' => $poi['provider_ref']['provider_id'], 'provider_place_id' => $poi['provider_ref']['provider_place_id']];
        $this->database->table(self::TABLE)->insertOrIgnore($key + [
            'poi_id' => (string) Str::uuid(), 'poi_json' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $poiId = (string) $this->database->table(self::TABLE)->where($key)->value('poi_id');
        $poi = ['poi_id' => $poiId] + $poi;
        $this->database->table(self::TABLE)->where('poi_id', $poiId)
            ->update(['poi_json' => json_encode($poi, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

        return $poi;
    }
}
