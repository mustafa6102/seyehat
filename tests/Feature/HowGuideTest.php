<?php

namespace Tests\Feature;

use App\Modules\Accommodation\Contracts\AccommodationSelectionReader;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Infrastructure\Persistence\DatabaseTripRequestRepository;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use App\Modules\FlightSearch\Contracts\FlightSelectionReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FixedWebPrincipalResolver;
use Tests\TestCase;

/** HOW-001 v3.19 owner-only contextual guide contract and Turkish browser channel. */
final class HowGuideTest extends TestCase
{
    private string $owned;

    private string $foreign;

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
        Schema::create('how_operation_idempotency', function ($table): void {
            $table->char('scope_hash', 64);
            $table->char('principal_hash', 64);
            $table->string('operation', 64);
            $table->string('trip_request_id', 255);
            $table->string('item_id', 255);
            $table->unsignedInteger('base_revision_version');
            $table->string('idempotency_key', 255);
            $table->char('request_fingerprint', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_json')->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('completed_at', 6)->nullable();
            $table->primary(['scope_hash', 'idempotency_key']);
        });
        Http::fake();
        $this->app->instance(FlightSelectionReader::class, new class implements FlightSelectionReader
        {
            public function find(string $tripRequestId): ?array
            {
                return null;
            }
        });
        $this->app->instance(AccommodationSelectionReader::class, new class implements AccommodationSelectionReader
        {
            public function find(string $tripRequestId): ?array
            {
                return null;
            }
        });

        $principal = new PrincipalRef('https://issuer.test', 'web-user');
        $this->app->instance(WebPrincipalResolver::class, new FixedWebPrincipalResolver($principal));
        $repository = new DatabaseTripRequestRepository(DB::connection());
        $this->owned = $repository->save($this->tripRequest()->ownedBy($principal))->id;
        $this->foreign = $repository->save($this->tripRequest()->ownedBy(new PrincipalRef('https://issuer.test', 'other')))->id;
    }

    #[Test]
    public function ts_how_001_01_unknown_operational_facts_are_never_invented(): void
    {
        $item = $this->addPoi($this->owned, $this->poi());
        $before = [DB::table('plan_revisions')->count(), DB::table('outbox_events')->count()];
        $this->get("/planlar/{$this->owned}/plan")->assertOk()
            ->assertSee('Nasıl yapacağım?')
            ->assertSee('data-guide-url="'.url($this->guideUrl($this->owned, $item)).'"', false);
        $response = $this->getJson($this->guideUrl($this->owned, $item), ['If-Match' => '"1"'])
            ->assertOk()->assertHeader('ETag', '"1"')->assertJsonPath('locale', 'tr-TR')
            ->assertJsonPath('has_stale_critical_evidence', false);

        $platform = collect($response->json('blocks'))->firstWhere('block_id', 'operational-platform')['evidence_slots'][0];
        self::assertSame(['UNKNOWN', null, null, 'UNVERIFIED', false], [
            $platform['knowledge_status'], $platform['display_value'], $platform['source_reference'],
            $platform['verification_status'], $platform['active_cta'],
        ]);
        self::assertSame($before, [DB::table('plan_revisions')->count(), DB::table('outbox_events')->count()]);
    }

    #[Test]
    public function ts_how_001_02_fresh_verified_booking_link_is_the_only_active_cta(): void
    {
        $poi = $this->poi();
        $poi['booking'] = $this->sourced('REQUIRED', 'FRESH', 'https://tickets.example/rome');
        $item = $this->addPoi($this->owned, $poi);
        $response = $this->getJson($this->guideUrl($this->owned, $item), ['If-Match' => '"1"'])->assertOk();
        $link = collect($response->json('blocks'))->firstWhere('block_id', 'operational-link')['evidence_slots'][0];

        self::assertSame('https://tickets.example/rome', $link['display_value']);
        self::assertSame('https://tickets.example/rome', $link['source_reference']);
        self::assertTrue($link['active_cta']);
    }

    #[Test]
    public function ts_how_001_03_stale_refresh_fails_closed_and_replays_without_plan_mutation(): void
    {
        $poi = $this->poi();
        $poi['opening_hours'] = $this->sourced(['weekly' => [['iso_weekday' => 1, 'open' => '09:00', 'close' => '17:00']]], 'STALE');
        $item = $this->addPoi($this->owned, $poi);
        $headers = ['If-Match' => '"1"', 'Idempotency-Key' => 'how-refresh-1'];
        $before = [DB::table('plan_revisions')->count(), DB::table('outbox_events')->count()];

        $this->getJson($this->guideUrl($this->owned, $item), ['If-Match' => '"1"'])
            ->assertOk()->assertJsonPath('has_stale_critical_evidence', true);
        $first = $this->postJson($this->guideUrl($this->owned, $item).'/refresh', [], $headers)
            ->assertStatus(503)->assertJsonPath('error.code', 'HOW_REVERIFY_UNAVAILABLE');
        $this->postJson($this->guideUrl($this->owned, $item).'/refresh', [], $headers)
            ->assertStatus(503)->assertExactJson($first->json());

        self::assertSame(1, DB::table('how_operation_idempotency')->count());
        self::assertSame($before, [DB::table('plan_revisions')->count(), DB::table('outbox_events')->count()]);
        Http::assertNothingSent();
    }

    #[Test]
    public function guide_operations_are_owner_only_and_validate_required_headers(): void
    {
        $item = $this->addPoi($this->owned, $this->poi());
        $this->getJson($this->guideUrl($this->foreign, $item), ['If-Match' => '"1"'])->assertForbidden();
        $this->getJson($this->guideUrl($this->owned, $item))->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->postJson($this->guideUrl($this->owned, $item).'/refresh', [], ['If-Match' => '"1"'])
            ->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    private function addPoi(string $tripRequestId, array $poi): string
    {
        DB::table('pois')->insert([
            'poi_id' => $poi['poi_id'], 'provider_id' => 'osm_overpass', 'provider_place_id' => 'node/1',
            'poi_json' => json_encode($poi, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post("/planlar/{$tripRequestId}/plan/gezilecek-yerler", [
            'poi_id' => $poi['poi_id'], 'expected_version' => '', 'idempotency_key' => 'how-add-'.$poi['poi_id'].'-0001',
        ])->assertRedirect();
        $items = json_decode((string) DB::table('plan_revisions')->where('version', 1)->value('items_json'), true, flags: JSON_THROW_ON_ERROR);

        return $items[0]['item_id'];
    }

    private function guideUrl(string $tripRequestId, string $itemId): string
    {
        return "/web-api/v1/trip-requests/{$tripRequestId}/plan/items/{$itemId}/guide";
    }

    private function tripRequest(): TripRequest
    {
        return TripRequest::draft([
            'origin_place' => ['kind' => 'city', 'value' => 'İstanbul', 'iana_timezone' => 'Europe/Istanbul'],
            'destination_place' => ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome'],
            'journey_type' => 'ONE_WAY', 'outbound_date' => ['type' => 'EXACT', 'date' => '2026-12-01'], 'traveler_count' => 1,
        ])->normalize();
    }

    private function poi(): array
    {
        return [
            'poi_id' => 'poi-how-1', 'provider_ref' => ['provider_id' => 'osm_overpass', 'provider_place_id' => 'node/1'],
            'name' => 'Kolezyum', 'categories' => [],
            'location' => ['latitude' => 41.89, 'longitude' => 12.49, 'address' => 'Roma', 'iana_timezone' => 'Europe/Rome'],
            'business_status' => $this->sourced('UNKNOWN', 'UNVERIFIED'),
            'opening_hours' => $this->sourced(null, 'UNVERIFIED'),
            'visit_duration' => ['minutes' => null, 'certainty' => 'UNKNOWN', 'source_reference' => null],
            'admission' => $this->sourced(['fee_required' => null, 'amount' => null], 'UNVERIFIED'),
            'booking' => $this->sourced('UNKNOWN', 'UNVERIFIED'),
        ];
    }

    private function sourced(mixed $value, string $status, string $source = 'https://www.openstreetmap.org/node/1'): array
    {
        return [
            'value' => $value, 'certainty' => $value === null || $value === 'UNKNOWN' ? 'UNKNOWN' : 'EXACT',
            'source_reference' => $source, 'fetched_at' => '2026-10-24T09:00:00+00:00',
            'verified_at' => $status === 'UNVERIFIED' ? null : '2026-10-24T09:00:00+00:00',
            'expires_at' => $status === 'UNVERIFIED' ? null : '2026-10-25T09:00:00+00:00', 'verification_status' => $status,
        ];
    }
}
