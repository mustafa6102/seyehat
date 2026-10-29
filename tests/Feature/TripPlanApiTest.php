<?php

namespace Tests\Feature;

use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Infrastructure\Persistence\DatabaseTripRequestRepository;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Shared\Outbox\Contracts\OutboxEvent;
use App\Shared\Outbox\Contracts\OutboxWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\TestCase;

/**
 * v3.16 TRIP-BLD-001 plan increment (OD-021, OD-027, ADR-003): OP-getTripRequestPlan, OP-addTripPlanPoiItem,
 * OP-removeTripPlanItem against SQLite tables mirroring the manual MariaDB SQL. Stored POIs only; no provider or IdP call.
 */
final class TripPlanApiTest extends TestCase
{
    private string $owned;

    private string $foreign;

    private string $legacy;

    private string $draft;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::table('trip_requests', function ($table): void {
            $table->string('owner_issuer', 255)->nullable();
            $table->string('owner_subject', 255)->nullable();
        });
        Schema::create('pois', function ($table): void {
            $table->char('poi_id', 36)->primary();
            $table->string('provider_id', 64);
            $table->string('provider_place_id', 64);
            $table->longText('poi_json');
            $table->timestamps(6);
        });
        Schema::create('trips', function ($table): void {
            $table->char('trip_id', 36)->primary();
            $table->string('trip_request_id', 255)->unique();
            $table->timestamp('created_at', 6);
        });
        Schema::create('plan_revisions', function ($table): void {
            $table->char('trip_id', 36);
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->longText('items_json');
            $table->longText('change_json');
            $table->string('idempotency_key', 255);
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at', 6);
            $table->primary(['trip_id', 'version']);
            $table->unique(['trip_id', 'idempotency_key']);
        });
        Http::fake();

        $repository = new DatabaseTripRequestRepository(DB::connection());
        $me = (new FixedBearerAuthenticator)->principal;
        $this->owned = $repository->save($this->tripRequest('SEARCHABLE')->ownedBy($me))->id;
        $this->foreign = $repository->save($this->tripRequest('SEARCHABLE')->ownedBy(new PrincipalRef('https://issuer.test', 'someone-else')))->id;
        $this->legacy = $repository->save($this->tripRequest('SEARCHABLE'))->id;
        $this->draft = $repository->save($this->tripRequest('DRAFT')->ownedBy($me))->id;
        $this->storePoi('poi-1', 'Kolezyum');
        $this->storePoi('poi-2', 'Pantheon');
    }

    #[Test]
    public function reading_without_a_trip_returns_null_plan_and_creates_nothing(): void
    {
        $this->plan($this->owned)->assertOk()->assertHeaderMissing('ETag')
            ->assertExactJson(['trip_request_id' => $this->owned, 'trip' => null, 'revision' => null,
                'day_frame' => ['status' => 'UNAVAILABLE', 'iana_timezone' => null, 'days' => [], 'unavailable_reason' => 'NOT_ROUND_TRIP']]);
        $this->assertNothingPersisted();
    }

    #[Test]
    public function non_owner_legacy_unknown_and_unauthenticated_callers_are_refused_without_state(): void
    {
        foreach ([$this->foreign, $this->legacy] as $tripRequestId) {
            $this->plan($tripRequestId)->assertStatus(403)->assertJsonPath('error.code', 'AUTHORIZATION_DENIED');
            $this->add($tripRequestId, 'poi-1', 'k-'.$tripRequestId, ['If-None-Match' => '*'])->assertStatus(403);
            $this->remove($tripRequestId, 'x', 'r-'.$tripRequestId, '"1"')->assertStatus(403);
        }
        $this->plan('999999')->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->flushHeaders();
        $this->withHeaders(['Idempotency-Key' => 'k', 'If-None-Match' => '*'])
            ->postJson("/api/v1/trip-requests/{$this->owned}/plan/items", ['poi_id' => 'poi-1'])
            ->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->assertNothingPersisted();
    }

    #[Test]
    public function first_add_creates_one_trip_and_revision_one_with_an_unscheduled_flexible_snapshot(): void
    {
        $response = $this->add($this->owned, 'poi-1', 'key-1', ['If-None-Match' => '*'])->assertCreated()->assertHeader('ETag', '"1"');

        $revision = $response->json('revision');
        self::assertSame(1, $revision['version']);
        self::assertSame('CURRENT', $revision['status']);
        self::assertSame(['base_version' => null, 'added_item_ids' => [$revision['items'][0]['item_id']], 'removed_item_ids' => [], 'moved_item_ids' => [], 'updated_item_ids' => [], 'reason_code' => 'RC-DECISION-TRANSITION-APPLIED'], $revision['change']);
        $item = $revision['items'][0];
        self::assertSame(['item_id', 'item_type', 'constraint_type', 'poi_snapshot', 'day_assignment', 'planning_duration'], array_keys($item));
        self::assertNull($item['day_assignment']);
        self::assertNull($item['planning_duration']);
        self::assertSame(['POI', 'FLEXIBLE'], [$item['item_type'], $item['constraint_type']]);
        // The snapshot is the stored canonical SCH-Poi unchanged: UNKNOWN stays UNKNOWN, UNVERIFIED stays UNVERIFIED.
        self::assertSame($this->poi('poi-1', 'Kolezyum'), $item['poi_snapshot']);
        self::assertSame(1, DB::table('trips')->count());
        self::assertSame(1, DB::table('plan_revisions')->count());
        self::assertSame([['trip_id' => $response->json('trip.trip_id'), 'revision_id' => '1', 'sequence' => null, 'correlation_id' => null]],
            DB::table('outbox_events')->get()->map(fn ($row) => (array) $row)->all());
        Http::assertNothingSent();
        $this->plan($this->owned)->assertOk()->assertHeader('ETag', '"1"')->assertJsonPath('revision.version', 1);
    }

    #[Test]
    public function missing_or_wrong_first_preconditions_are_refused(): void
    {
        $this->add($this->owned, 'poi-1', 'k1', [])->assertStatus(400);
        $this->add($this->owned, 'poi-1', 'k2', ['If-None-Match' => '"1"'])->assertStatus(400);
        $this->add($this->owned, 'poi-1', 'k3', ['If-None-Match' => '*', 'If-Match' => '"1"'])->assertStatus(400);
        $this->add($this->owned, 'poi-1', 'k4', ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('error.code', 'REVISION_STALE');
        $this->flushHeaders();
        $this->withToken(FixedBearerAuthenticator::TOKEN)->withHeaders(['If-None-Match' => '*'])
            ->postJson("/api/v1/trip-requests/{$this->owned}/plan/items", ['poi_id' => 'poi-1'])
            ->assertStatus(400);
        $this->assertNothingPersisted();
    }

    #[Test]
    public function a_second_first_mutation_cannot_create_another_trip(): void
    {
        $this->add($this->owned, 'poi-1', 'a', ['If-None-Match' => '*'])->assertCreated();
        $this->add($this->owned, 'poi-2', 'b', ['If-None-Match' => '*'])->assertStatus(409)->assertJsonPath('error.code', 'REVISION_STALE');

        self::assertSame(1, DB::table('trips')->count());
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('trips')->insert(['trip_id' => 'other', 'trip_request_id' => $this->owned, 'created_at' => now()]);
    }

    #[Test]
    public function later_mutations_need_the_current_version_and_supersede_the_previous_revision(): void
    {
        $first = $this->add($this->owned, 'poi-1', 'a', ['If-None-Match' => '*'])->json('revision.items.0.item_id');
        $second = $this->add($this->owned, 'poi-2', 'b', ['If-Match' => '"1"'])->assertCreated()->assertHeader('ETag', '"2"');

        self::assertSame([$first, $second->json('revision.items.1.item_id')], array_column($second->json('revision.items'), 'item_id'));
        self::assertSame(1, $second->json('revision.change.base_version'));
        self::assertSame(['SUPERSEDED', 'CURRENT'], DB::table('plan_revisions')->orderBy('version')->pluck('status')->all());

        // Stale If-Match: 409, nothing written.
        $this->add($this->owned, 'poi-1', 'c', ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('error.code', 'REVISION_STALE');
        self::assertSame(2, DB::table('plan_revisions')->count());
        self::assertSame(2, DB::table('outbox_events')->count());
    }

    #[Test]
    public function removing_an_item_creates_the_next_revision_and_keeps_the_other_items(): void
    {
        $a = $this->add($this->owned, 'poi-1', 'a', ['If-None-Match' => '*'])->json('revision.items.0.item_id');
        $b = $this->add($this->owned, 'poi-2', 'b', ['If-Match' => '"1"'])->json('revision.items.1.item_id');

        $removed = $this->remove($this->owned, $a, 'c', '"2"')->assertOk()->assertHeader('ETag', '"3"');
        self::assertSame([$b], array_column($removed->json('revision.items'), 'item_id'));
        self::assertSame(['base_version' => 2, 'added_item_ids' => [], 'removed_item_ids' => [$a], 'moved_item_ids' => [], 'updated_item_ids' => [], 'reason_code' => 'RC-DECISION-TRANSITION-APPLIED'], $removed->json('revision.change'));
        // Earlier revisions are unchanged and traceable.
        self::assertCount(2, json_decode(DB::table('plan_revisions')->where('version', 2)->value('items_json'), true));

        $this->remove($this->owned, 'unknown-item', 'd', '"3"')->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->remove($this->owned, $b, 'e', '"2"')->assertStatus(409);
        $this->remove($this->owned, $b, 'f', null)->assertStatus(400);
        $this->remove($this->draft, $b, 'g', '"1"')->assertStatus(404);
        self::assertSame(3, DB::table('plan_revisions')->count());
    }

    #[Test]
    public function idempotency_key_replays_the_original_result_or_conflicts(): void
    {
        $original = $this->add($this->owned, 'poi-1', 'same', ['If-None-Match' => '*'])->assertCreated();
        $replay = $this->add($this->owned, 'poi-1', 'same', ['If-None-Match' => '*'])->assertCreated()->assertHeader('ETag', '"1"');

        self::assertSame($original->json('revision'), $replay->json('revision'));
        self::assertSame(1, DB::table('plan_revisions')->count());
        self::assertSame(1, DB::table('outbox_events')->count());
        $this->add($this->owned, 'poi-2', 'same', ['If-None-Match' => '*'])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->remove($this->owned, $original->json('revision.items.0.item_id'), 'same', '"1"')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        self::assertSame(1, DB::table('plan_revisions')->count());
    }

    #[Test]
    public function client_supplied_fields_and_non_searchable_requests_are_refused(): void
    {
        foreach ([['poi_snapshot' => []], ['day' => '2026-12-01'], ['start_time' => '10:00'], ['constraint_type' => 'FIXED'], ['duration' => 60], ['owner_subject' => 'x'], ['feasible' => true]] as $extra) {
            $this->add($this->owned, 'poi-1', 'k-'.array_key_first($extra), ['If-None-Match' => '*'], $extra)->assertStatus(400);
        }
        $this->add($this->owned, 'missing-poi', 'k-missing', ['If-None-Match' => '*'])->assertStatus(404);
        $this->add($this->draft, 'poi-1', 'k-draft', ['If-None-Match' => '*'])->assertStatus(422)->assertJsonPath('error.code', 'TRIP_REQUEST_INCOMPLETE');
        $this->assertNothingPersisted();
    }

    #[Test]
    public function an_outbox_failure_rolls_back_the_whole_mutation(): void
    {
        $this->app->instance(OutboxWriter::class, new class implements OutboxWriter
        {
            public function append(OutboxEvent $event): void
            {
                throw new RuntimeException('outbox unavailable');
            }
        });

        $this->add($this->owned, 'poi-1', 'k', ['If-None-Match' => '*'])->assertStatus(500);
        $this->assertNothingPersisted();
    }

    #[Test]
    public function responses_and_outbox_carry_no_principal_or_token(): void
    {
        $add = $this->add($this->owned, 'poi-1', 'k', ['If-None-Match' => '*']);
        $content = $add->getContent().$this->plan($this->owned)->getContent().json_encode(DB::table('outbox_events')->get())
            .json_encode(DB::table('plan_revisions')->get()).json_encode(DB::table('trips')->get());
        foreach (['api-user', 'issuer.test', FixedBearerAuthenticator::TOKEN, 'owner'] as $secret) {
            self::assertStringNotContainsString($secret, $content);
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    private function add(string $tripRequestId, string $poiId, string $key, array $headers, array $extra = []): TestResponse
    {
        $this->flushHeaders();

        return $this->withToken(FixedBearerAuthenticator::TOKEN)
            ->withHeaders(['Idempotency-Key' => $key] + $headers)
            ->postJson("/api/v1/trip-requests/{$tripRequestId}/plan/items", ['poi_id' => $poiId] + $extra);
    }

    private function remove(string $tripRequestId, string $itemId, string $key, ?string $ifMatch): TestResponse
    {
        $this->flushHeaders();

        return $this->withToken(FixedBearerAuthenticator::TOKEN)
            ->withHeaders(['Idempotency-Key' => $key] + ($ifMatch === null ? [] : ['If-Match' => $ifMatch]))
            ->deleteJson("/api/v1/trip-requests/{$tripRequestId}/plan/items/{$itemId}");
    }

    private function plan(string $tripRequestId): TestResponse
    {
        $this->flushHeaders();

        return $this->withToken(FixedBearerAuthenticator::TOKEN)->getJson("/api/v1/trip-requests/{$tripRequestId}/plan");
    }

    private function assertNothingPersisted(): void
    {
        self::assertSame([0, 0, 0], [DB::table('trips')->count(), DB::table('plan_revisions')->count(), DB::table('outbox_events')->count()]);
    }

    /** SEARCHABLE = complete and normalized; DRAFT = missing traveler_count, so normalize() keeps it DRAFT. */
    private function tripRequest(string $status): TripRequest
    {
        return TripRequest::draft([
            'origin_place' => ['kind' => 'city', 'value' => 'Istanbul', 'iana_timezone' => 'Europe/Istanbul'],
            'destination_place' => ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome'],
            'journey_type' => 'ONE_WAY',
            'outbound_date' => ['type' => 'EXACT', 'date' => '2026-12-01'],
        ] + ($status === 'SEARCHABLE' ? ['traveler_count' => 1] : []))->normalize();
    }

    private function storePoi(string $poiId, string $name): void
    {
        DB::table('pois')->insert(['poi_id' => $poiId, 'provider_id' => 'osm_overpass', 'provider_place_id' => 'node/'.strlen($name),
            'poi_json' => json_encode($this->poi($poiId, $name), JSON_PRESERVE_ZERO_FRACTION), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> SCH-Poi with UNKNOWN / UNVERIFIED facts */
    private function poi(string $poiId, string $name): array
    {
        $sourced = fn (mixed $value, string $certainty): array => ['value' => $value, 'certainty' => $certainty,
            'source_reference' => 'https://www.openstreetmap.org/node/1', 'fetched_at' => '2026-10-06T09:00:00+00:00',
            'verified_at' => null, 'expires_at' => null, 'verification_status' => 'UNVERIFIED'];

        return [
            'poi_id' => $poiId,
            'provider_ref' => ['provider_id' => 'osm_overpass', 'provider_place_id' => 'node/1'],
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
