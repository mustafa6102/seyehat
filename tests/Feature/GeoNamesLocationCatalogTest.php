<?php

namespace Tests\Feature;

use App\Modules\ProviderAcl\Application\CircuitBreaker;
use App\Modules\ProviderAcl\Application\ResilientProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use App\Modules\ProviderAcl\Domain\Resilience\ResiliencePolicy;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GeoNamesLocationCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        // Test-only values; production username and ADR-006 policy values are not in the repository.
        config([
            'services.geonames.username' => 'test-user',
            'provider_resilience.providers.geonames' => [
                'version' => 'test-1',
                'timeout_ms' => 1000,
                'retry' => ['max_attempts' => 2, 'base_delay_ms' => 10, 'max_delay_ms' => 20],
                'circuit_breaker' => ['failure_threshold' => 5, 'open_seconds' => 30],
                'rate_limit' => ['max_requests' => 100, 'per_seconds' => 60],
            ],
        ]);
        $this->app->bind(ProviderCallGuard::class, fn ($app): ProviderCallGuard => new ResilientProviderCallGuard(
            $app->make(ResiliencePolicies::class),
            $app->make(CircuitBreaker::class),
            $app->make(RateLimiter::class),
            fn (int $ceilingMs): int => 0,
        ));
    }

    #[Test]
    public function search_maps_only_canonical_entries_with_verified_timezone_and_allowed_kind(): void
    {
        Http::fake(['secure.geonames.org/searchJSON*' => Http::response(['geonames' => [
            $this->row(745044, 'İstanbul', 'PPLA', 'Europe/Istanbul', 'İstanbul'),
            $this->row(6299784, 'İstanbul Havalimanı', 'AIRP', 'Europe/Istanbul', 'İstanbul'),
            $this->row(7874047, 'Sirkeci', 'RSTN', 'Europe/Istanbul', 'İstanbul'),
            $this->row(1, 'İstanbul İli', 'ADM1', 'Europe/Istanbul', 'İstanbul'),
            $this->row(2, 'Saatsiz', 'PPL', null, 'İstanbul'),
            $this->row(3, 'Bozuk', 'PPL', 'Mars/Olympus', 'İstanbul'),
            ['geonameId' => 'x', 'name' => 'Eksik'],
        ]])]);

        $this->getJson('/konumlar/ara?ulke=tr&q=ist')
            ->assertOk()
            ->assertExactJson(['locations' => [
                ['id' => 'geonames:745044', 'kind' => 'city', 'label' => 'İstanbul', 'country_code' => 'TR', 'iana_timezone' => 'Europe/Istanbul'],
                ['id' => 'geonames:6299784', 'kind' => 'airport', 'label' => 'İstanbul Havalimanı, İstanbul', 'country_code' => 'TR', 'iana_timezone' => 'Europe/Istanbul'],
                ['id' => 'geonames:7874047', 'kind' => 'station', 'label' => 'Sirkeci, İstanbul', 'country_code' => 'TR', 'iana_timezone' => 'Europe/Istanbul'],
            ]]);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://secure.geonames.org/searchJSON?')
            && str_contains($request->url(), 'name_startsWith=ist&country=TR')
            && str_contains($request->url(), 'style=FULL&lang=tr')
            && substr_count($request->url(), 'featureCode=') === 8);
    }

    #[Test]
    public function countries_are_turkish_labelled_and_invalid_rows_are_dropped(): void
    {
        Http::fake(['secure.geonames.org/countryInfoJSON*' => Http::response(['geonames' => [
            ['countryCode' => 'TR', 'countryName' => 'Türkiye'],
            ['countryCode' => 'IT', 'countryName' => 'İtalya'],
            ['countryCode' => 'tr1', 'countryName' => 'Bozuk'],
            'bozuk',
        ]])]);

        $countries = $this->getJson('/konumlar/ulkeler')->assertOk()->json('countries');

        self::assertEqualsCanonicalizing([['code' => 'IT', 'label' => 'İtalya'], ['code' => 'TR', 'label' => 'Türkiye']], $countries);
    }

    #[Test]
    public function timeout_and_provider_failure_return_turkish_errors_without_secrets(): void
    {
        Http::fake(['secure.geonames.org/*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out for searchJSON?username=test-user')
            ->pushFailedConnection('cURL error 28: Operation timed out for searchJSON?username=test-user')
            ->push('<html>upstream error test-user</html>', 503)
            ->push('<html>upstream error test-user</html>', 503)]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->message, $event->context];
        });

        $timeout = $this->getJson('/konumlar/ara?ulke=TR&q=ist')->assertStatus(503);
        $failure = $this->getJson('/konumlar/ara?ulke=TR&q=ist')->assertStatus(503);

        self::assertSame('PROVIDER_TIMEOUT', $timeout->json('error.code'));
        self::assertSame('Sağlayıcı yanıtı zamanında alınamadı. Konum araması şu anda yapılamıyor.', $timeout->json('error.message_tr'));
        self::assertNull($failure->json('error.code'));
        self::assertNotSame([], $logged);
        foreach ([$timeout->getContent(), $failure->getContent(), json_encode($logged, JSON_THROW_ON_ERROR)] as $body) {
            self::assertStringNotContainsString('test-user', $body);
            self::assertStringNotContainsString('cURL', $body);
            self::assertStringNotContainsString('upstream', $body);
        }
        Http::assertSentCount(4);
    }

    #[Test]
    public function malformed_payload_and_no_result_never_produce_fabricated_locations(): void
    {
        Http::fake(['secure.geonames.org/*' => Http::sequence()
            ->push(['geonames' => 'bozuk'])
            ->push(['status' => ['message' => 'no result found', 'value' => 15]])
            ->push(['status' => ['message' => 'no result found', 'value' => 15]])]);

        $this->getJson('/konumlar/ara?ulke=TR&q=ist')->assertStatus(503);
        $this->getJson('/konumlar/ara?ulke=TR&q=zzz')->assertOk()->assertExactJson(['locations' => []]);
        $this->getJson('/konumlar/cozumle?kimlik=geonames:999')->assertNotFound();
        $this->getJson('/konumlar/cozumle?kimlik=../etc')->assertNotFound();
        $this->getJson('/konumlar/ara?ulke=TR&q=i')->assertOk()->assertExactJson(['locations' => []]);

        Http::assertSentCount(3);
    }

    #[Test]
    public function credit_limit_is_mapped_to_rate_limited_and_not_retried(): void
    {
        Http::fake(['secure.geonames.org/*' => Http::response(['status' => ['message' => 'the hourly limit of 1000 credits for test-user has been exceeded', 'value' => 19]])]);

        $response = $this->getJson('/konumlar/ara?ulke=TR&q=ist')->assertStatus(503);

        self::assertSame('PROVIDER_RATE_LIMITED', $response->json('error.code'));
        self::assertStringNotContainsString('test-user', $response->getContent());
        Http::assertSentCount(1);
    }

    #[Test]
    public function catalog_stays_inactive_without_credentials_and_page_exposes_it_only_when_configured(): void
    {
        Http::fake();

        $this->get('/')
            ->assertSee('data-location-search-url', false)
            ->assertSee('GeoNames')
            ->assertDontSee('test-user');

        config(['services.geonames.username' => null]);

        $this->get('/')
            ->assertSee('Ülkeyi seçebilirsiniz; ancak şehir ve havalimanı araması şu anda kullanılamıyor.')
            ->assertSee('data-location-countries-url', false)
            ->assertDontSee('data-location-search-url', false);
        $this->getJson('/konumlar/ara?ulke=TR&q=ist')
            ->assertStatus(503)
            ->assertJsonPath('error.message_tr', 'Konum araması henüz kullanıma açılmadı.');
        Http::assertNothingSent();
    }

    #[Test]
    public function default_geonames_profile_is_complete_and_stays_below_free_tier_limits(): void
    {
        $policy = ResiliencePolicy::fromConfig((require config_path('provider_resilience.php'))['providers']['geonames']);

        self::assertNotNull($policy);
        $perHour = $policy->rateLimitMaxRequests * 3600 / $policy->rateLimitPerSeconds;
        self::assertLessThan(1000, $perHour);
        self::assertLessThan(10000, $perHour * 24);
        // Only scoped profiles exist: two location-discovery profiles, the Duffel Flights profile adopted for the
        // first concrete flight adapter (v3.11), Duffel Stays (v3.14, OD-023), dev-only OSM Overpass
        // POI (v3.15, OD-025) and Ticketmaster event discovery (v3.22, OD-EVT-001-TM). There is no global default.
        self::assertSame([], array_diff_key(
            (require config_path('provider_resilience.php'))['providers'],
            ['geonames' => true, 'duffel_places' => true, 'duffel_flights' => true, 'duffel_stays' => true, 'osm_overpass' => true, 'ticketmaster' => true],
        ));
    }

    #[Test]
    public function invalid_query_inputs_are_rejected_without_calling_geonames(): void
    {
        Http::fake();

        foreach (['/konumlar/ara?ulke=TUR&q=ist', '/konumlar/ara?ulke=TR&q[]=ist', '/konumlar/ara?ulke=TR&q=ist%00x', '/konumlar/ara?ulke=TR&q='.str_repeat('a', 81), '/konumlar/cozumle?kimlik[]=1'] as $url) {
            $this->getJson($url)
                ->assertStatus(422)
                ->assertExactJson(['error' => ['code' => 'SCHEMA_VALIDATION_FAILED', 'message_tr' => 'İstek biçimi veya alanları geçerli değil.', 'correlation_id' => null, 'details' => null]]);
        }
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $name, string $featureCode, ?string $timezone, string $region): array
    {
        return [
            'geonameId' => $id,
            'name' => $name,
            'countryCode' => 'TR',
            'fcode' => $featureCode,
            'adminName1' => $region,
            'lat' => '41.01384',
            'lng' => '28.94966',
            'timezone' => $timezone === null ? null : ['timeZoneId' => $timezone, 'gmtOffset' => 3],
        ];
    }
}
