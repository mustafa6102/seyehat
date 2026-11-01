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
        self::assertSame(['item_id', 'item_type', 'constraint_type', 'poi_snapshot', 'day_assignment', 'planning_duration'], array_keys($item));
        self::assertNull($item['planning_duration']);
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
    public function a_one_way_request_lists_items_without_days_and_explains_why(): void
    {
        $this->add('poi-1', '', 'key-ow-00000000001');

        $this->get("/planlar/{$this->owned}/plan")->assertOk()
            ->assertSee('Plan günleri yalnız gidiş-dönüş seyahatlerde oluşturulur; tek yön talepte yerler bir güne atanamaz.')
            ->assertSee('Güne atanmamış yerler')
            ->assertSee('Esnek · gün ve saat atanmadı')
            ->assertDontSee('data-day-assignment', false)
            ->assertDontSee('Uygulanabilirlik değerlendirilmedi');
    }

    #[Test]
    public function the_owner_assigns_and_orders_places_by_day_through_the_session_web_channel(): void
    {
        $id = (new DatabaseTripRequestRepository(DB::connection()))->save(TripRequest::draft([
            'origin_place' => ['kind' => 'city', 'value' => 'Istanbul', 'iana_timezone' => 'Europe/Istanbul'],
            'destination_place' => ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome'],
            'journey_type' => 'ROUND_TRIP',
            'outbound_date' => ['type' => 'EXACT', 'date' => '2026-12-01'],
            'return_date' => ['type' => 'EXACT', 'date' => '2026-12-02'],
            'traveler_count' => 1,
        ])->normalize()->ownedBy($this->web->principal))->id;
        $this->post("/planlar/{$id}/plan/gezilecek-yerler", ['poi_id' => 'poi-1', 'expected_version' => '', 'idempotency_key' => 'key-rt-00000000001']);
        $this->post("/planlar/{$id}/plan/gezilecek-yerler", ['poi_id' => 'poi-2', 'expected_version' => '1', 'idempotency_key' => 'key-rt-00000000002']);
        [$colosseum, $pantheon] = array_column(json_decode(DB::table('plan_revisions')->where('version', 2)->value('items_json'), true), 'item_id');

        $this->get("/planlar/{$id}/plan")->assertOk()
            ->assertSee('1. gün · 1 Aralık 2026')
            ->assertSee('2. gün · 2 Aralık 2026')
            ->assertSee('Bu güne henüz yer atanmadı.')
            ->assertSee('Günler Europe/Rome saat dilimindeki yerel tarihlerdir', false)
            ->assertSee('data-plan-version="2"', false)
            ->assertSee('data-url="'.url("/web-api/v1/trip-requests/{$id}/plan/items/{$colosseum}/day-assignment").'"', false)
            ->assertDontSee('Uygulanabilirlik değerlendirilmedi')
            ->assertDontSee('Gün gün planınız henüz oluşturulmadı.');

        $put = fn (string $itemId, array $body, string $key, string $version): TestResponse => $this->putJson(
            "/web-api/v1/trip-requests/{$id}/plan/items/{$itemId}/day-assignment", $body, ['If-Match' => "\"{$version}\"", 'Idempotency-Key' => $key]);
        $put($colosseum, ['date' => '2026-12-02'], 'web-day-1', '2')->assertOk()->assertJsonPath('revision.items.0.day_assignment.feasibility_status', 'NOT_EVALUATED');
        $put($pantheon, ['date' => '2026-12-02', 'position' => 1], 'web-day-2', '3')->assertOk();
        $put($pantheon, ['date' => '2026-12-02'], 'web-day-3', '3')->assertStatus(409)->assertJsonPath('error.code', 'REVISION_STALE');

        $html = $this->get("/planlar/{$id}/plan")->assertOk()
            ->assertSee('Uygulanabilirlik değerlendirilmedi')
            ->assertSee('Esnek · 1. sırada · saat atanmadı')
            ->assertSee('Esnek · 2. sırada · saat atanmadı')
            ->assertSee('Güne atanmamış yer yok.')
            ->assertSee('Günü veya sırası değişti: Pantheon')
            ->assertSee('Doğrulanmamış bilgi')
            ->assertSee('Ziyaret süresi bilinmiyor')
            ->getContent();
        // Day 2 lists Pantheon before Kolezyum (stored order); no time, duration or feasibility claim is rendered.
        $day = substr($html, strpos($html, 'data-plan-day="2026-12-02"'));
        self::assertLessThan(strpos($day, 'Kolezyum'), strpos($day, 'Pantheon'));
        self::assertStringNotContainsString('Uygulanabilir.', $html);
    }

    #[Test]
    public function the_day_assignment_web_route_needs_the_session_owner_and_csrf(): void
    {
        $route = Route::getRoutes()->getByName('web-api.trip-plan.items.day-assignment');
        self::assertContains('web', $route->gatherMiddleware());
        self::assertContains('auth.web-session', $route->gatherMiddleware());
        self::assertNotContains(ValidateCsrfToken::class, $route->excludedMiddleware());

        $this->web->principal = new PrincipalRef('https://issuer.test', 'someone-else');
        $this->putJson("/web-api/v1/trip-requests/{$this->owned}/plan/items/x/day-assignment", ['date' => null], ['If-Match' => '"1"', 'Idempotency-Key' => 'k'])
            ->assertStatus(403)->assertJsonPath('error.code', 'AUTHORIZATION_DENIED');
        $this->web->principal = null;
        $this->putJson("/web-api/v1/trip-requests/{$this->owned}/plan/items/x/day-assignment", ['date' => null], ['If-Match' => '"1"', 'Idempotency-Key' => 'k'])
            ->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->assertCounts(0, 0);
    }

    #[Test]
    public function ground_connection_screen_fails_closed_without_verified_ground_data(): void
    {
        $this->withSession(['trip_requests.created' => [$this->owned]])
            ->get('/taleplerim')->assertOk()
            ->assertSee(route('ground-connections.show', ['tripRequestId' => $this->owned]), false)
            ->assertSee('Kara bağlantıları');

        $this->get("/planlar/{$this->owned}/kara-baglantilari")->assertOk()
            ->assertSee('Istanbul → Roma')
            ->assertSee('Doğrulanmış veri bulunamadı')
            ->assertSee('Kısmi; kara ulaşımı maliyetleri eksik')
            ->assertDontSee('UNKNOWN')
            ->assertDontSee('PARTIAL')
            ->assertDontSee('GND-001')
            ->assertSee('Sağlayıcı destekli fiyat, süre, kaynak, rezervasyon bağlantısı ve geçerlilik bilgileri henüz doğrulanmadı.')
            ->assertSee('Kara bağlantısının ücreti ve süresi')
            ->assertSee('İstasyona erişim ve bekleme süresi')
            ->assertSee('Son kilometre ulaşımı')
            ->assertSee('Tren, otobüs veya başka bir kara segmenti uydurulmadı.')
            ->assertDontSee('Kara ulaşımı ara')
            ->assertDontSee('Teklif seç');

        $this->web->principal = new PrincipalRef('https://issuer.test', 'someone-else');
        $this->get("/planlar/{$this->owned}/kara-baglantilari")->assertForbidden();
        $this->web->principal = null;
        $this->get("/planlar/{$this->owned}/kara-baglantilari")->assertStatus(401);
        $this->assertCounts(0, 0);
    }

    #[Test]
    public function door_to_door_screen_requires_owned_trip_and_never_compares_incomplete_routes(): void
    {
        $this->get("/planlar/{$this->owned}/kapidan-kapiya-karsilastirma")->assertOk()
            ->assertSee('Istanbul → Roma')
            ->assertSee('Karşılaştırma yapılamıyor')
            ->assertSee('Başlangıç noktasından terminale doğrulanmış erişim süresi')
            ->assertSee('Terminalden varış noktasına doğrulanmış erişim süresi')
            ->assertSee('Timezone-normalize edilmiş segment zamanları')
            ->assertSee('Doğrulanmış toplam maliyet bileşenleri')
            ->assertSee(route('ground-connections.show', ['tripRequestId' => $this->owned]), false)
            ->assertDontSee('En hızlı rota')
            ->assertDontSee('En ucuz rota');

        $this->web->principal = new PrincipalRef('https://issuer.test', 'someone-else');
        $this->get("/planlar/{$this->owned}/kapidan-kapiya-karsilastirma")->assertForbidden();

        $this->web->principal = null;
        $this->get("/planlar/{$this->owned}/kapidan-kapiya-karsilastirma")->assertStatus(401);

        $this->get('/planlar/bilinmeyen/kapidan-kapiya-karsilastirma')->assertNotFound();
        $this->assertCounts(0, 0);
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
        foreach (['Plan öğesi ve kaynak ayrıntıları', 'Yer kimliği', 'Kaynak: osm_overpass', 'Kaynak: node/1'] as $technicalLabel) {
            self::assertStringNotContainsString($technicalLabel, $html);
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
        DB::table('pois')->insert(['poi_id' => $poiId, 'provider_id' => 'osm_overpass', 'provider_place_id' => 'node/'.$poiId,
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
