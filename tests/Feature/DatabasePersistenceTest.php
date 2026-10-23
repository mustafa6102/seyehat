<?php

namespace Tests\Feature;

use App\Modules\Api\Domain\TripRequest\LocationRef;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Infrastructure\Persistence\DatabaseTripRequestRepository;
use App\Modules\FlightSearch\Application\FlightSearchDispatcher;
use App\Modules\FlightSearch\Application\FlightSearchService;
use App\Modules\FlightSearch\Domain\FlightSearchJobRecord;
use App\Modules\FlightSearch\Infrastructure\Persistence\DatabaseFlightSearchJobRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Real persistence adapters (ADR-011 job state, TRIP-001 v3.9) against the project migrations on an
 * in-memory SQLite connection; MySQL is never touched by automated tests.
 */
final class DatabasePersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.foreign_key_constraints' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    #[Test]
    public function flight_search_job_terminal_states_are_stable_and_idempotency_key_is_unique(): void
    {
        $jobs = new DatabaseFlightSearchJobRepository(DB::connection());
        $record = new FlightSearchJobRecord('11111111-1111-4111-8111-111111111111', 'key-1', 'QUEUED', null, null, null, null, 'trip-1');

        self::assertTrue($jobs->insertIfAbsent($record));
        self::assertFalse($jobs->insertIfAbsent(new FlightSearchJobRecord('22222222-2222-4222-8222-222222222222', 'key-1', 'QUEUED', null, null, null, null, 'trip-1')));
        self::assertNull($jobs->find('22222222-2222-4222-8222-222222222222'));

        $jobs->markRunning($record->searchId);
        $jobs->recordPartial($record->searchId, ['offers' => [['offer_id' => 'A-1']]]);
        self::assertSame(['PARTIAL', ['offers' => [['offer_id' => 'A-1']]]], [$jobs->find($record->searchId)->status, $jobs->find($record->searchId)->result]);

        $jobs->complete($record->searchId, ['offers' => [['offer_id' => 'A-1'], ['offer_id' => 'B-1']]]);
        $completed = $jobs->find($record->searchId);

        // A late partial, failure, expiry or restart can never overwrite a terminal snapshot.
        $jobs->recordPartial($record->searchId, ['offers' => []]);
        $jobs->fail($record->searchId, 'PROVIDER_TIMEOUT', 'x');
        $jobs->expire($record->searchId);
        $jobs->markRunning($record->searchId);

        self::assertEquals($completed, $jobs->find($record->searchId));
        self::assertSame(['search_id', 'trip_request_id', 'status', 'result', 'error', 'expires_at'], array_keys($completed->toContract()));
        self::assertSame('trip-1', $completed->tripRequestId);
    }

    #[Test]
    public function failure_is_persisted_and_expiry_applies_only_to_non_terminal_jobs(): void
    {
        $jobs = new DatabaseFlightSearchJobRepository(DB::connection());
        $jobs->save(new FlightSearchJobRecord('33333333-3333-4333-8333-333333333333', 'key-3', 'QUEUED', null, null, null, new DateTimeImmutable('2026-09-01T00:00:00+00:00'), 'trip-1'));
        $jobs->save(new FlightSearchJobRecord('44444444-4444-4444-8444-444444444444', 'key-4', 'QUEUED', null, null, null, new DateTimeImmutable('2026-09-01T00:00:00+00:00'), 'trip-1'));
        $jobs->fail('44444444-4444-4444-8444-444444444444', null, 'Uçuş araması tamamlanamadı. Lütfen yeniden deneyin.');

        $service = new FlightSearchService($jobs, new NullSearchDispatcher);
        $now = new DateTimeImmutable('2026-09-29T10:00:00+00:00');

        self::assertSame('EXPIRED', $service->poll('33333333-3333-4333-8333-333333333333', $now)?->status);
        self::assertSame('Arama işinin geçerlilik süresi doldu.', $service->poll('33333333-3333-4333-8333-333333333333', $now)?->errorMessageTr);
        $failed = $service->poll('44444444-4444-4444-8444-444444444444', $now);
        self::assertSame(['FAILED', null, 'Uçuş araması tamamlanamadı. Lütfen yeniden deneyin.'], [$failed?->status, $failed?->errorCode, $failed?->errorMessageTr]);
        self::assertNull($service->poll('unknown', $now));
    }

    #[Test]
    public function start_is_idempotent_against_the_database_and_dispatches_once(): void
    {
        $jobs = new DatabaseFlightSearchJobRepository(DB::connection());
        $dispatcher = new NullSearchDispatcher;
        $service = new FlightSearchService($jobs, $dispatcher);

        $first = $service->start('same-key', 'trip-1');
        $second = $service->start('same-key', 'trip-1');

        self::assertSame($first->searchId, $second->searchId);
        self::assertSame(1, $dispatcher->dispatched);
        self::assertSame(1, DB::table('flight_search_jobs')->count());
        // Repeated polling is read-only.
        $service->poll($first->searchId);
        $service->poll($first->searchId);
        self::assertSame('QUEUED', $jobs->find($first->searchId)?->status);
        self::assertSame(1, $dispatcher->dispatched);
    }

    #[Test]
    public function trip_request_journey_round_trips_and_legacy_rows_survive_the_v39_migration_unchanged(): void
    {
        $repository = new DatabaseTripRequestRepository(DB::connection());
        $place = LocationRef::fromNullable(['kind' => 'airport', 'value' => 'duffel:arp_ist_tr', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'IST']]);
        $saved = $repository->save(TripRequest::draft([
            'origin_place' => $place->toContract(),
            'destination_place' => $place->toContract(),
            'journey_type' => 'ROUND_TRIP',
            'outbound_date' => ['type' => 'FLEXIBLE', 'start_date' => '2026-10-15', 'end_date' => '2026-10-20'],
            'return_date' => ['type' => 'EXACT', 'date' => '2026-10-27'],
            'traveler_count' => 2,
            'passenger_composition' => ['adults' => 2, 'children' => 0, 'infants' => 0],
        ])->normalize());

        $found = $repository->find((string) $saved->id)?->toView();
        self::assertSame(['SEARCHABLE', 'ROUND_TRIP'], [$found?->status, $found?->journeyType]);
        self::assertSame(['type' => 'FLEXIBLE', 'start_date' => '2026-10-15', 'end_date' => '2026-10-20'], $found?->outboundDate);
        self::assertSame(['type' => 'EXACT', 'date' => '2026-10-27'], $found?->returnDate);
        self::assertNull($found?->dateWindow);
        self::assertSame(['type' => 'IATA_AIRPORT', 'code' => 'IST'], $found?->originPlace['aviation_identity']);
        self::assertSame(['adults' => 2, 'children' => 0, 'infants' => 0], $found?->passengerComposition);

        // Legacy: a pre-v3.9 row written before the journey migration keeps its data and gets no guessed journey.
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();
        $legacyPlace = json_encode(['kind' => 'city', 'value' => 'geonames:745044', 'iana_timezone' => 'Europe/Istanbul'], JSON_THROW_ON_ERROR);
        $legacyId = DB::table('trip_requests')->insertGetId([
            'status' => 'SEARCHABLE', 'origin_place' => $legacyPlace, 'destination_place' => $legacyPlace,
            'date_window' => '{"start_date":"2026-10-15","end_date":"2026-10-20"}', 'traveler_count' => 1, 'preferences' => null,
        ]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $row = DB::table('trip_requests')->where('id', $legacyId)->first();
        self::assertSame(['{"start_date":"2026-10-15","end_date":"2026-10-20"}', 'SEARCHABLE', null, null, null, null], [$row->date_window, $row->status, $row->journey_type, $row->outbound_date, $row->return_date, $row->passenger_composition]);
        $legacy = $repository->find((string) $legacyId)?->toView();
        self::assertTrue($legacy?->isLegacyDateModel());
        self::assertSame(['start_date' => '2026-10-15', 'end_date' => '2026-10-20'], $legacy?->dateWindow);
        // Reading never rewrites the row.
        self::assertSame('{"start_date":"2026-10-15","end_date":"2026-10-20"}', DB::table('trip_requests')->where('id', $legacyId)->value('date_window'));
    }
}

final class NullSearchDispatcher implements FlightSearchDispatcher
{
    public int $dispatched = 0;

    public function dispatch(string $searchId): void
    {
        $this->dispatched++;
    }
}
