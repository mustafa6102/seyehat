<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ActiveFeatureStorageMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    #[Test]
    public function active_flight_accommodation_poi_trip_plan_and_how_storage_is_installed_by_migrations(): void
    {
        self::assertTrue(Schema::hasColumns('trip_requests', ['room_count', 'owner_issuer', 'owner_subject']));

        foreach ([
            'trip_request_flight_selections',
            'accommodation_search_jobs',
            'trip_request_accommodation_selections',
            'poi_discovery_jobs',
            'pois',
            'trips',
            'plan_revisions',
            'how_operation_idempotency',
            'events',
            'event_discovery_jobs',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "{$table} migration zincirinde bulunamadı.");
        }

        $indexes = collect(DB::select("PRAGMA index_list('how_operation_idempotency')"))->pluck('name');
        self::assertContains('how_idem_trip_request_index', $indexes);
        self::assertContains('how_idem_status_created_index', $indexes);

        $foreignKeys = DB::select("PRAGMA foreign_key_list('plan_revisions')");
        self::assertTrue(collect($foreignKeys)->contains(
            static fn (object $key): bool => $key->table === 'trips' && $key->from === 'trip_id' && $key->to === 'trip_id'
        ));
    }

    #[Test]
    public function active_storage_migration_rolls_back_cleanly(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();

        foreach ([
            'trip_request_flight_selections',
            'accommodation_search_jobs',
            'trip_request_accommodation_selections',
            'poi_discovery_jobs',
            'pois',
            'trips',
            'plan_revisions',
            'how_operation_idempotency',
            'events',
            'event_discovery_jobs',
        ] as $table) {
            self::assertFalse(Schema::hasTable($table), "{$table} rollback sonrasında hâlâ mevcut.");
        }
    }
}
