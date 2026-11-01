<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductionSchemaSqlAuditTest extends TestCase
{
    #[Test]
    public function diagnostic_and_verification_sql_cover_the_active_persistence_inventory(): void
    {
        $diagnostics = $this->sql('production-schema-diagnostics-v3.23.sql');
        $verify = $this->sql('production-schema-verify-v3.23.sql');
        $requiredTables = [
            'migrations', 'jobs', 'job_batches', 'failed_jobs', 'outbox_events', 'provider_capabilities',
            'trip_requests', 'flight_search_jobs', 'travelers', 'trip_request_flight_selections',
            'accommodation_search_jobs', 'trip_request_accommodation_selections', 'poi_discovery_jobs',
            'pois', 'trips', 'plan_revisions', 'how_operation_idempotency',
            'events', 'event_discovery_jobs',
        ];

        foreach ($requiredTables as $table) {
            self::assertStringContainsString("'{$table}'", $diagnostics, "Diagnostics misses {$table}.");
            self::assertStringContainsString("'{$table}'", $verify, "Verification misses {$table}.");
        }

        foreach ([
            'accommodation_search_jobs_trip_request_id_status_index',
            'poi_discovery_jobs_trip_request_id_status_index',
            'pois_provider_place_unique',
            'plan_revisions_trip_fk',
            'how_idem_status_created_index',
            'uq_events_provider',
            'uq_event_discovery_jobs_key',
            'idx_event_discovery_jobs_trip',
        ] as $databaseObject) {
            self::assertStringContainsString($databaseObject, $verify);
        }

        self::assertStringContainsString('2026_10_29_010000_create_evt_001_storage_tables', $verify);
    }

    #[Test]
    public function delivered_sql_is_read_only_or_an_explicit_non_executable_gate(): void
    {
        foreach (['production-schema-diagnostics-v3.23.sql', 'production-schema-verify-v3.23.sql'] as $file) {
            $statements = $this->withoutComments($this->sql($file));
            self::assertDoesNotMatchRegularExpression('/\b(?:CREATE|ALTER|DROP|TRUNCATE|INSERT|UPDATE|DELETE|REPLACE)\b/i', $statements, $file);
        }

        $corrective = $this->sql('production-missing-changes-v3.23.sql');
        self::assertStringContainsString('STATUS: NOT READY', $corrective);
        self::assertStringContainsString('No DDL/DML is authorized', $corrective);
        self::assertDoesNotMatchRegularExpression(
            '/^\s*(?:CREATE|ALTER|DROP|TRUNCATE|INSERT|UPDATE|DELETE|REPLACE)\b/im',
            $this->withoutComments($corrective),
        );

        $ticketmasterCapability = (string) file_get_contents(database_path('manual/ticketmaster_capability_seed.sql'));
        self::assertStringContainsString('STATUS: NOT READY', $ticketmasterCapability);
        self::assertDoesNotMatchRegularExpression(
            '/^\s*(?:CREATE|ALTER|DROP|TRUNCATE|INSERT|UPDATE|DELETE|REPLACE)\b/im',
            $this->withoutComments($ticketmasterCapability),
        );
    }

    private function sql(string $file): string
    {
        return (string) file_get_contents(database_path("sql/{$file}"));
    }

    private function withoutComments(string $sql): string
    {
        return (string) preg_replace('/^\s*--.*$/m', '', $sql);
    }
}
