<?php

namespace Tests\Feature;

use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Infrastructure\Persistence\DatabaseTripRequestRepository;
use App\Modules\Authorization\Contracts\PrincipalRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\TestCase;

/**
 * v3.18 DAY-001 (OD-029 A): derived day_frame and OP-assignTripPlanItemDay (TS-DAY-001-05..08) on SQLite tables mirroring
 * the manual MariaDB SQL. No time, duration or feasibility exists anywhere in the responses; POI facts stay as stored.
 */
final class TripPlanDayAssignmentTest extends TestCase
{
    private DatabaseTripRequestRepository $repository;

    private string $owned;

    private string $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Http::fake();

        $this->repository = new DatabaseTripRequestRepository(DB::connection());
        $this->owned = $this->save($this->roundTrip());
        $this->foreign = $this->save($this->roundTrip(), new PrincipalRef('https://issuer.test', 'someone-else'));
        foreach (['poi-1' => 'Kolezyum', 'poi-2' => 'Pantheon', 'poi-3' => 'Trevi Çeşmesi'] as $poiId => $name) {
            DB::table('pois')->insert(['poi_id' => $poiId, 'provider_id' => 'osm_overpass', 'provider_place_id' => 'node/'.$poiId,
                'poi_json' => json_encode($this->poi($poiId, $name), JSON_PRESERVE_ZERO_FRACTION), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    #[Test]
    public function the_day_frame_is_every_exact_date_from_outbound_to_return_in_the_destination_timezone(): void
    {
        $this->plan($this->owned)->assertOk()->assertJsonPath('day_frame', [
            'status' => 'AVAILABLE', 'iana_timezone' => 'Europe/Rome', 'days' => ['2026-12-01', '2026-12-02', '2026-12-03'], 'unavailable_reason' => null,
        ]);
        self::assertSame(0, DB::table('trips')->count(), 'reading never creates a Trip');
    }

    #[Test]
    public function no_days_are_derived_from_one_way_flexible_or_unknown_timezone_requests_and_assignment_is_refused(): void
    {
        $cases = [
            'NOT_ROUND_TRIP' => $this->tripRequest(['journey_type' => 'ONE_WAY']),
            'DATES_NOT_EXACT' => $this->roundTrip(['return_date' => ['type' => 'FLEXIBLE', 'start_date' => '2026-12-03', 'end_date' => '2026-12-05']]),
            'DESTINATION_TIMEZONE_UNKNOWN' => $this->roundTrip(['destination_place' => ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => '+01:00']]),
        ];
        foreach ($cases as $reason => $tripRequest) {
            $id = $this->save($tripRequest);
            $this->plan($id)->assertOk()->assertJsonPath('day_frame', ['status' => 'UNAVAILABLE', 'iana_timezone' => null, 'days' => [], 'unavailable_reason' => $reason]);
            $itemId = $this->addPoi($id, 'poi-1', "add-{$reason}")->json('revision.items.0.item_id');

            $this->assign($id, $itemId, ['date' => '2026-12-01'], "day-{$reason}", '"1"')
                ->assertStatus(422)->assertJsonPath('error.code', 'DAY_FRAME_UNAVAILABLE');
            self::assertSame(1, DB::table('plan_revisions')->where('trip_id', $this->tripId($id))->count(), $reason);
            // Removing an assignment never needs days.
            $this->assign($id, $itemId, ['date' => null], "unassign-{$reason}", '"1"')->assertOk()->assertJsonPath('revision.version', 2);
        }
    }

    #[Test]
    public function assigning_creates_a_new_revision_with_an_unevaluated_day_and_keeps_the_snapshot_and_previous_revision(): void
    {
        $itemId = $this->addPoi($this->owned, 'poi-1', 'add-1')->json('revision.items.0.item_id');

        $response = $this->assign($this->owned, $itemId, ['date' => '2026-12-02'], 'day-1', '"1"')->assertOk()->assertHeader('ETag', '"2"');

        $item = $response->json('revision.items.0');
        self::assertSame(['date' => '2026-12-02', 'position' => 1, 'feasibility_status' => 'NOT_EVALUATED'], $item['day_assignment']);
        self::assertSame(['item_id', 'item_type', 'constraint_type', 'poi_snapshot', 'day_assignment', 'planning_duration'], array_keys($item));
        self::assertNull($item['planning_duration']);
        self::assertSame('FLEXIBLE', $item['constraint_type']);
        // UNKNOWN stays UNKNOWN, UNVERIFIED stays UNVERIFIED (RULE-DAY-001-05).
        self::assertSame($this->poi('poi-1', 'Kolezyum'), $item['poi_snapshot']);
        self::assertSame(['base_version' => 1, 'added_item_ids' => [], 'removed_item_ids' => [], 'moved_item_ids' => [$itemId], 'updated_item_ids' => [], 'reason_code' => 'RC-DECISION-TRANSITION-APPLIED'], $response->json('revision.change'));
        $json = (string) $response->getContent();
        foreach (['start_time', 'end_time', 'duration', 'feasible', 'travel'] as $absent) {
            self::assertStringNotContainsString("\"{$absent}", $json);
        }
        self::assertSame(['SUPERSEDED', 'CURRENT'], DB::table('plan_revisions')->orderBy('version')->pluck('status')->all());
        self::assertNull(json_decode(DB::table('plan_revisions')->where('version', 1)->value('items_json'), true)[0]['day_assignment'], 'revision 1 unchanged');
        self::assertSame(2, DB::table('outbox_events')->count());
    }

    #[Test]
    public function dates_outside_the_frame_and_positions_out_of_range_are_refused_without_state(): void
    {
        $itemId = $this->addPoi($this->owned, 'poi-1', 'add-1')->json('revision.items.0.item_id');

        $this->assign($this->owned, $itemId, ['date' => '2026-11-30'], 'k1', '"1"')->assertStatus(422)->assertJsonPath('error.code', 'DAY_ASSIGNMENT_INVALID');
        $this->assign($this->owned, $itemId, ['date' => '2026-12-04'], 'k2', '"1"')->assertStatus(422)->assertJsonPath('error.code', 'DAY_ASSIGNMENT_INVALID');
        $this->assign($this->owned, $itemId, ['date' => '2026-12-01', 'position' => 2], 'k3', '"1"')->assertStatus(422)->assertJsonPath('error.code', 'DAY_ASSIGNMENT_INVALID');
        self::assertSame(1, DB::table('plan_revisions')->count());
        self::assertSame(1, DB::table('outbox_events')->count());
    }

    #[Test]
    public function malformed_requests_are_schema_errors(): void
    {
        $itemId = $this->addPoi($this->owned, 'poi-1', 'add-1')->json('revision.items.0.item_id');
        $bodies = [
            [], ['date' => '2026-02-30'], ['date' => '01.12.2026'], ['date' => 20261201], ['date' => '2026-12-01', 'start_time' => '10:00'],
            ['date' => '2026-12-01', 'duration' => 60], ['date' => '2026-12-01', 'feasibility_status' => 'FEASIBLE'], ['date' => null, 'position' => 1],
            ['date' => '2026-12-01', 'position' => '1'], ['date' => '2026-12-01', 'position' => 0], ['date' => '2026-12-01', 'position' => 1.5], ['position' => 1],
        ];
        foreach ($bodies as $index => $body) {
            $this->assign($this->owned, $itemId, $body, "bad-{$index}", '"1"')->assertStatus(400)->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED');
        }
        $this->assign($this->owned, $itemId, ['date' => '2026-12-01'], 'no-if-match', null)->assertStatus(400);
        $this->assign($this->owned, $itemId, ['date' => '2026-12-01'], 'inm', null, ['If-None-Match' => '*'])->assertStatus(400);
        $this->flushHeaders();
        $this->withToken(FixedBearerAuthenticator::TOKEN)->withHeaders(['If-Match' => '"1"'])
            ->putJson("/api/v1/trip-requests/{$this->owned}/plan/items/{$itemId}/day-assignment", ['date' => '2026-12-01'])->assertStatus(400);
        self::assertSame(1, DB::table('plan_revisions')->count());
    }

    #[Test]
    public function reordering_moving_unassigning_and_removing_keep_every_day_contiguous(): void
    {
        $a = $this->addPoi($this->owned, 'poi-1', 'add-a')->json('revision.items.0.item_id');
        $b = $this->addPoi($this->owned, 'poi-2', 'add-b', '"1"')->json('revision.items.1.item_id');
        $c = $this->addPoi($this->owned, 'poi-3', 'add-c', '"2"')->json('revision.items.2.item_id');
        $this->assign($this->owned, $a, ['date' => '2026-12-01'], 'd-a', '"3"')->assertOk();
        $this->assign($this->owned, $b, ['date' => '2026-12-01'], 'd-b', '"4"')->assertOk();
        $this->assign($this->owned, $c, ['date' => '2026-12-01'], 'd-c', '"5"')->assertOk();
        self::assertSame([$a => ['2026-12-01', 1], $b => ['2026-12-01', 2], $c => ['2026-12-01', 3]], $this->days());

        $this->assign($this->owned, $c, ['date' => '2026-12-01', 'position' => 1], 'up-c', '"6"')->assertOk();
        self::assertSame([$a => ['2026-12-01', 2], $b => ['2026-12-01', 3], $c => ['2026-12-01', 1]], $this->days());

        $this->assign($this->owned, $a, ['date' => '2026-12-03'], 'move-a', '"7"')->assertOk();
        self::assertSame([$a => ['2026-12-03', 1], $b => ['2026-12-01', 2], $c => ['2026-12-01', 1]], $this->days());

        $this->assign($this->owned, $c, ['date' => null], 'unassign-c', '"8"')->assertOk();
        self::assertSame([$a => ['2026-12-03', 1], $b => ['2026-12-01', 1], $c => null], $this->days());

        $this->assign($this->owned, $c, ['date' => '2026-12-03', 'position' => 1], 'c-first', '"9"')->assertOk();
        self::assertSame([$a => ['2026-12-03', 2], $b => ['2026-12-01', 1], $c => ['2026-12-03', 1]], $this->days());

        $this->remove($c, 'remove-c', '"10"')->assertOk()->assertJsonPath('revision.change.moved_item_ids', []);
        self::assertSame([$a => ['2026-12-03', 1], $b => ['2026-12-01', 1]], $this->days());
        self::assertSame(range(1, 11), DB::table('plan_revisions')->orderBy('version')->pluck('version')->map(fn ($v) => (int) $v)->all());
    }

    #[Test]
    public function stale_revisions_idempotent_replays_and_key_reuse_follow_od_027(): void
    {
        $a = $this->addPoi($this->owned, 'poi-1', 'add-a')->json('revision.items.0.item_id');
        $this->addPoi($this->owned, 'poi-2', 'add-b', '"1"');

        $this->assign($this->owned, $a, ['date' => '2026-12-01'], 'stale', '"1"')->assertStatus(409)->assertJsonPath('error.code', 'REVISION_STALE');

        $first = $this->assign($this->owned, $a, ['date' => '2026-12-01'], 'same-key', '"2"')->assertOk();
        $replay = $this->assign($this->owned, $a, ['date' => '2026-12-01'], 'same-key', '"2"')->assertOk()->assertHeader('ETag', '"3"');
        self::assertSame($first->json('revision'), $replay->json('revision'));
        $this->assign($this->owned, $a, ['date' => '2026-12-02'], 'same-key', '"2"')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        self::assertSame(3, DB::table('plan_revisions')->count());
        self::assertSame(3, DB::table('outbox_events')->count());

        $this->assign($this->owned, 'missing-item', ['date' => '2026-12-01'], 'missing', '"3"')->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        self::assertSame(3, DB::table('plan_revisions')->count());
    }

    #[Test]
    public function only_the_authenticated_owner_can_assign_and_refusals_leave_no_state(): void
    {
        $this->assign($this->foreign, 'any', ['date' => '2026-12-01'], 'f', '"1"')->assertStatus(403)->assertJsonPath('error.code', 'AUTHORIZATION_DENIED');
        $legacy = $this->repository->save($this->roundTrip())->id;
        $this->assign($legacy, 'any', ['date' => '2026-12-01'], 'l', '"1"')->assertStatus(403);
        $this->assign('999999', 'any', ['date' => '2026-12-01'], 'u', '"1"')->assertStatus(404);
        $this->flushHeaders();
        $this->withHeaders(['Idempotency-Key' => 'anon', 'If-Match' => '"1"'])
            ->putJson("/api/v1/trip-requests/{$this->owned}/plan/items/any/day-assignment", ['date' => '2026-12-01'])
            ->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        self::assertSame([0, 0, 0], [DB::table('trips')->count(), DB::table('plan_revisions')->count(), DB::table('outbox_events')->count()]);
    }

    #[Test]
    public function revisions_written_before_v3_18_are_read_as_unassigned_and_can_be_assigned(): void
    {
        $tripId = '00000000-0000-4000-8000-0000000000aa';
        DB::table('trips')->insert(['trip_id' => $tripId, 'trip_request_id' => $this->owned, 'created_at' => '2026-10-07 10:00:00.000000']);
        DB::table('plan_revisions')->insert(['trip_id' => $tripId, 'version' => 1, 'status' => 'CURRENT',
            'items_json' => json_encode([['item_id' => 'legacy-item', 'item_type' => 'POI', 'constraint_type' => 'FLEXIBLE', 'poi_snapshot' => $this->poi('poi-1', 'Kolezyum')]], JSON_PRESERVE_ZERO_FRACTION),
            'change_json' => json_encode(['base_version' => null, 'added_item_ids' => ['legacy-item'], 'removed_item_ids' => [], 'reason_code' => 'RC-DECISION-TRANSITION-APPLIED']),
            'idempotency_key' => 'legacy', 'request_fingerprint' => str_repeat('0', 64), 'created_at' => '2026-10-07 10:00:00.000000']);

        $this->plan($this->owned)->assertOk()->assertJsonPath('revision.items.0.day_assignment', null)->assertJsonPath('revision.change.moved_item_ids', []);
        $this->assign($this->owned, 'legacy-item', ['date' => '2026-12-03'], 'legacy-day', '"1"')->assertOk()
            ->assertJsonPath('revision.items.0.day_assignment.date', '2026-12-03');
    }

    /** @return array<string, array{0: string, 1: int}|null> item_id => [date, position] of the CURRENT revision */
    private function days(): array
    {
        $items = $this->plan($this->owned)->json('revision.items');

        return array_combine(array_column($items, 'item_id'), array_map(
            fn (array $item): ?array => $item['day_assignment'] === null ? null : [$item['day_assignment']['date'], $item['day_assignment']['position']],
            $items,
        ));
    }

    /** @param  array<string, string>  $headers */
    private function assign(string $tripRequestId, string $itemId, array $body, string $key, ?string $ifMatch, array $headers = []): TestResponse
    {
        $this->flushHeaders();

        return $this->withToken(FixedBearerAuthenticator::TOKEN)
            ->withHeaders(['Idempotency-Key' => $key] + ($ifMatch === null ? [] : ['If-Match' => $ifMatch]) + $headers)
            ->putJson("/api/v1/trip-requests/{$tripRequestId}/plan/items/{$itemId}/day-assignment", $body);
    }

    private function addPoi(string $tripRequestId, string $poiId, string $key, ?string $ifMatch = null): TestResponse
    {
        $this->flushHeaders();

        return $this->withToken(FixedBearerAuthenticator::TOKEN)
            ->withHeaders(['Idempotency-Key' => $key] + ($ifMatch === null ? ['If-None-Match' => '*'] : ['If-Match' => $ifMatch]))
            ->postJson("/api/v1/trip-requests/{$tripRequestId}/plan/items", ['poi_id' => $poiId])->assertCreated();
    }

    private function remove(string $itemId, string $key, string $ifMatch): TestResponse
    {
        $this->flushHeaders();

        return $this->withToken(FixedBearerAuthenticator::TOKEN)->withHeaders(['Idempotency-Key' => $key, 'If-Match' => $ifMatch])
            ->deleteJson("/api/v1/trip-requests/{$this->owned}/plan/items/{$itemId}");
    }

    private function plan(string $tripRequestId): TestResponse
    {
        $this->flushHeaders();

        return $this->withToken(FixedBearerAuthenticator::TOKEN)->getJson("/api/v1/trip-requests/{$tripRequestId}/plan");
    }

    private function tripId(string $tripRequestId): string
    {
        return (string) DB::table('trips')->where('trip_request_id', $tripRequestId)->value('trip_id');
    }

    private function save(TripRequest $tripRequest, ?PrincipalRef $owner = null): string
    {
        return $this->repository->save($tripRequest->ownedBy($owner ?? (new FixedBearerAuthenticator)->principal))->id;
    }

    /** @param  array<string, mixed>  $override */
    private function roundTrip(array $override = []): TripRequest
    {
        return $this->tripRequest($override + ['journey_type' => 'ROUND_TRIP', 'return_date' => ['type' => 'EXACT', 'date' => '2026-12-03']]);
    }

    /** @param  array<string, mixed>  $fields */
    private function tripRequest(array $fields): TripRequest
    {
        return TripRequest::draft($fields + [
            'origin_place' => ['kind' => 'city', 'value' => 'Istanbul', 'iana_timezone' => 'Europe/Istanbul'],
            'destination_place' => ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome'],
            'outbound_date' => ['type' => 'EXACT', 'date' => '2026-12-01'],
            'traveler_count' => 1,
        ])->normalize();
    }

    /** @return array<string, mixed> SCH-Poi with UNKNOWN / UNVERIFIED facts */
    private function poi(string $poiId, string $name): array
    {
        $sourced = fn (mixed $value, string $certainty): array => ['value' => $value, 'certainty' => $certainty,
            'source_reference' => 'https://www.openstreetmap.org/node/1', 'fetched_at' => '2026-10-06T09:00:00+00:00',
            'verified_at' => null, 'expires_at' => null, 'verification_status' => 'UNVERIFIED'];

        return [
            'poi_id' => $poiId,
            'provider_ref' => ['provider_id' => 'osm_overpass', 'provider_place_id' => 'node/'.$poiId],
            'name' => $name,
            'categories' => [],
            'location' => ['latitude' => 41.89, 'longitude' => 12.49, 'address' => null, 'iana_timezone' => null],
            'business_status' => $sourced('UNKNOWN', 'UNKNOWN'),
            'opening_hours' => $sourced(null, 'UNKNOWN'),
            'visit_duration' => ['minutes' => null, 'certainty' => 'UNKNOWN', 'source_reference' => null],
            'admission' => $sourced(['fee_required' => null, 'amount' => null], 'UNKNOWN'),
            'booking' => $sourced('UNKNOWN', 'UNKNOWN'),
        ];
    }
}
