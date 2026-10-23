<?php

namespace Tests\Feature;

use App\Modules\Accommodation\Contracts\AccommodationSelectionReader;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Infrastructure\Persistence\DatabaseTripRequestRepository;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use App\Modules\FlightSearch\Contracts\FlightSelectionReader;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FixedWebPrincipalResolver;
use Tests\TestCase;

/**
 * v3.16 web channel for unscheduled FLEXIBLE POI plan items: session principal + TripRequestOwnership + TripPlanService.
 * SQLite tables mirror the manual MariaDB SQL; POIs are stored fakes. No OIDC or OSM call is made.
 */
final class TripPlanWebTest extends TestCase
{
    private FixedWebPrincipalResolver $web;

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
        $this->web = new FixedWebPrincipalResolver(new PrincipalRef('https://issuer.test', 'web-user'));
        $this->app->instance(WebPrincipalResolver::class, $this->web);

        $repository = new DatabaseTripRequestRepository(DB::connection());
        $this->owned = $repository->save($this->tripRequest()->ownedBy($this->web->principal))->id;
        $this->foreign = $repository->save($this->tripRequest()->ownedBy(new PrincipalRef('https://issuer.test', 'someone-else')))->id;
        $this->storePoi('poi-1', 'Kolezyum');
        $this->storePoi('poi-2', 'Pantheon');
    }

    #[Test]
    public function the_owner_without_a_trip_sees_the_empty_state_and_nothing_is_created(): void
    {
        $this->get("/planlar/{$this->owned}/plan")->assertOk()
            ->assertSee('Plandaki gezilecek yerler')
            ->assertSee('Plana henüz gezilecek yer eklenmedi.')
            ->assertSee('data-plan-access="OWNER"', false)
            ->assertSee('data-plan-version=""', false);
        $this->assertCounts(0, 0);
    }

    #[Test]
    public function adding_creates_the_first_revision_from_the_stored_poi_and_renders_its_uncertainty(): void
    {
        $this->add('poi-1', '', 'key-add-0000000001')->assertRedirect("/planlar/{$this->owned}/plan#plan-pois")
            ->assertSessionHas('plan_status', 'Gezilecek yer plana eklendi.');

        $this->assertCounts(1, 1);
        $html = $this->get("/planlar/{$this->owned}/plan")->assertOk()
            ->assertSee('Kolezyum')
            ->assertSee('Esnek · gün ve saat atanmadı')
            ->assertSee('Doğrulanmamış bilgi')
            ->assertSee('Giriş ücreti bilinmiyor')
            ->assertSee('Çalışma saatleri bilinmiyor')
            ->assertSee('Rezervasyon gerekip gerekmediği bilinmiyor')
            ->assertSee('Ziyaret süresi bilinmiyor')
            ->assertSee('Plandan çıkar')
            ->assertSee('OpenStreetMap katkıda bulunanlar')
            ->assertSee('data-plan-version="1"', false)
            ->assertDontSee('Giriş ücretsiz')
            ->getContent();
        self::assertStringNotContainsString('Rezervasyon sayfası', $html);
        Http::assertNothingSent();
    }

    #[Test]
    public function forged_browser_fields_cannot_change_the_item(): void
    {
        $this->add('poi-1', '', 'key-forged-00000001', [
            'poi_snapshot' => ['name' => 'Sahte'], 'constraint_type' => 'FIXED', 'day' => '2026-12-01', 'start_time' => '10:00',
            'duration' => 60, 'owner_issuer' => 'https://evil.test', 'owner_subject' => 'attacker', 'feasible' => '1',
        ])->assertSessionHas('plan_status');

        $item = json_decode(DB::table('plan_revisions')->value('items_json'), true)[0];
        self::assertSame(['item_id', 'item_type', 'constraint_type', 'poi_snapshot'], array_keys($item));
        self::assertSame(['POI', 'FLEXIBLE', 'Kolezyum'], [$item['item_type'], $item['constraint_type'], $item['poi_snapshot']['name']]);
        self::assertSame($this->poi('poi-1', 'Kolezyum'), $item['poi_snapshot']);
        self::assertStringNotContainsString('attacker', (string) json_encode(DB::table('trip_requests')->get()));
    }

    #[Test]
    public function multiple_items_are_shown_and_removal_creates_the_next_revision_keeping_other_items(): void
    {
        $this->add('poi-1', '', 'key-a-000000000001');
        $this->add('poi-2', '1', 'key-b-000000000001')->assertSessionHas('plan_status');
        $this->get("/planlar/{$this->owned}/plan")->assertSee('Kolezyum')->assertSee('Pantheon');
        [$first, $second] = array_column(json_decode(DB::table('plan_revisions')->where('version', 2)->value('items_json'), true), 'item_id');

        $this->remove($first, '2', 'key-r-000000000001')->assertSessionHas('plan_status', 'Gezilecek yer plandan çıkarıldı.');

        $current = json_decode(DB::table('plan_revisions')->where('status', 'CURRENT')->value('items_json'), true);
        self::assertSame([$second], array_column($current, 'item_id'));
        self::assertSame([1, 2, 3], DB::table('plan_revisions')->orderBy('version')->pluck('version')->map(fn ($v) => (int) $v)->all());
        self::assertCount(2, json_decode(DB::table('plan_revisions')->where('version', 2)->value('items_json'), true), 'old revisions stay unchanged');
        $this->get("/planlar/{$this->owned}/plan")->assertDontSee('Kolezyum')->assertSee('Pantheon');
    }

    #[Test]
    public function the_current_revision_change_and_reason_are_shown_as_stored(): void
    {
        $this->get("/planlar/{$this->owned}/plan")->assertDontSee('Son plan değişikliği');

        $this->add('poi-1', '', 'key-d-000000000001');
        $this->get("/planlar/{$this->owned}/plan")
            ->assertSee('Son plan değişikliği')
            ->assertSee('data-plan-revision="1"', false)
            ->assertSee('Plan sürümü 1 (ilk sürüm)')
            ->assertSee('Eklendi: Kolezyum')
            ->assertSee('Gerekçe: Kullanıcı değişikliği')
            ->assertDontSee('Çıkarıldı:')
            ->assertDontSee('RC-DECISION-TRANSITION-APPLIED');

        $this->add('poi-2', '1', 'key-d-000000000002');
        $itemId = json_decode(DB::table('plan_revisions')->where('version', 2)->value('items_json'), true)[0]['item_id'];
        $this->remove($itemId, '2', 'key-d-000000000003');

        $html = $this->get("/planlar/{$this->owned}/plan")
            ->assertSee('Plan sürümü 3 (sürüm 2 üzerine)')
            ->assertSee('Çıkarıldı: 1 gezilecek yer')
            ->assertSee('Önceki sürüm (sürüm 2) değiştirilmeden saklanır.')
            ->assertDontSee('Eklendi:')
            ->getContent();
        self::assertMatchesRegularExpression('/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2} UTC/', $html);

        // Removing the last item still shows the change next to the empty state.
        $last = json_decode(DB::table('plan_revisions')->where('version', 3)->value('items_json'), true)[0]['item_id'];
        $this->remove($last, '3', 'key-d-000000000004');
        $this->get("/planlar/{$this->owned}/plan")->assertSee('Plan sürümü 4 (sürüm 3 üzerine)')->assertSee('Plana henüz gezilecek yer eklenmedi.');
    }

    #[Test]
    public function non_owners_never_see_the_revision_change(): void
    {
        $this->add('poi-1', '', 'key-n-000000000001');
        $this->web->principal = new PrincipalRef('https://issuer.test', 'someone-else');
        $this->get("/planlar/{$this->owned}/plan")->assertOk()->assertDontSee('Son plan değişikliği')->assertDontSee('data-plan-revision', false);
    }

    #[Test]
    public function a_stale_page_never_overwrites_a_newer_revision(): void
    {
        $this->add('poi-1', '', 'key-s-000000000001');
        $this->add('poi-2', '1', 'key-s-000000000002');

        $this->add('poi-1', '1', 'key-s-000000000003')
            ->assertSessionHas('plan_error', 'Plan bu sırada değişti. Sayfayı yenileyip yeniden deneyin.');
        $this->add('poi-1', '', 'key-s-000000000004')->assertSessionHas('plan_error', 'Plan bu sırada değişti. Sayfayı yenileyip yeniden deneyin.');
        $this->assertCounts(1, 2);
    }

    #[Test]
    public function a_double_submit_with_the_same_key_creates_one_revision(): void
    {
        $this->add('poi-1', '', 'key-double-00000001')->assertSessionHas('plan_status');
        $this->add('poi-1', '', 'key-double-00000001')->assertSessionHas('plan_status');

        $this->assertCounts(1, 1);
        $this->add('poi-2', '', 'key-double-00000001')->assertSessionHas('plan_error');
        $this->assertCounts(1, 1);
    }

    #[Test]
    public function signed_out_and_non_owner_users_neither_see_the_items_nor_mutate(): void
    {
        $this->add('poi-1', '', 'key-o-000000000001');

        $this->web->principal = null;
        $this->get("/planlar/{$this->owned}/plan")->assertOk()->assertSee('giriş yapın')->assertDontSee('Kolezyum')->assertDontSee('Plandan çıkar');
        $this->add('poi-2', '1', 'key-o-000000000002')->assertSessionHas('plan_error', 'Planı düzenlemek için giriş yapmanız gerekiyor.');

        $this->web->principal = new PrincipalRef('https://issuer.test', 'someone-else');
        $this->get("/planlar/{$this->owned}/plan")->assertOk()->assertSee('yalnız plan sahibi')->assertDontSee('Kolezyum');
        $this->add('poi-2', '1', 'key-o-000000000003')->assertSessionHas('plan_error', 'Bu planı yalnız sahibi düzenleyebilir.');
        $itemId = json_decode(DB::table('plan_revisions')->value('items_json'), true)[0]['item_id'];
        $this->remove($itemId, '1', 'key-o-000000000004')->assertSessionHas('plan_error', 'Bu planı yalnız sahibi düzenleyebilir.');

        $this->assertCounts(1, 1);
    }

    #[Test]
    public function rendered_html_carries_no_principal_owner_or_token_data(): void
    {
        $this->add('poi-1', '', 'key-h-000000000001');
        $html = $this->get("/planlar/{$this->owned}/plan")->getContent();

        foreach (['web-user', 'issuer.test', 'owner_issuer', 'owner_subject', 'someone-else'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }

    #[Test]
    public function mutation_routes_use_the_web_session_and_csrf_while_the_api_stays_bearer_only(): void
    {
        $webGroup = app(Kernel::class)->getMiddlewareGroups()['web'];
        self::assertContains(ValidateCsrfToken::class, $webGroup);
        foreach (['trip-plan.pois.store', 'trip-plan.pois.destroy'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertContains('web', $route->gatherMiddleware(), $name);
            self::assertNotContains(ValidateCsrfToken::class, $route->excludedMiddleware(), $name);
        }
        // A signed-in browser session does not authenticate the contracted bearer API.
        $this->getJson("/api/v1/trip-requests/{$this->owned}/plan")->assertStatus(401);
        $this->postJson("/api/v1/trip-requests/{$this->owned}/plan/items", ['poi_id' => 'poi-1'], ['Idempotency-Key' => 'k', 'If-None-Match' => '*'])
            ->assertStatus(401);
        $this->assertCounts(0, 0);
    }

    /** @param  array<string, mixed>  $extra */
    private function add(string $poiId, string $expectedVersion, string $key, array $extra = []): TestResponse
    {
        return $this->post("/planlar/{$this->owned}/plan/gezilecek-yerler", ['poi_id' => $poiId, 'expected_version' => $expectedVersion, 'idempotency_key' => $key] + $extra);
    }

    private function remove(string $itemId, string $expectedVersion, string $key): TestResponse
    {
        return $this->delete("/planlar/{$this->owned}/plan/gezilecek-yerler/{$itemId}", ['expected_version' => $expectedVersion, 'idempotency_key' => $key]);
    }

    private function assertCounts(int $trips, int $revisions): void
    {
        self::assertSame([$trips, $revisions, $revisions], [DB::table('trips')->count(), DB::table('plan_revisions')->count(), DB::table('outbox_events')->count()]);
    }

    private function tripRequest(): TripRequest
    {
        return TripRequest::draft([
            'origin_place' => ['kind' => 'city', 'value' => 'Istanbul', 'iana_timezone' => 'Europe/Istanbul'],
            'destination_place' => ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome'],
            'journey_type' => 'ONE_WAY',
            'outbound_date' => ['type' => 'EXACT', 'date' => '2026-12-01'],
            'traveler_count' => 1,
        ])->normalize();
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
