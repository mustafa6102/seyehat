<?php

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\ProviderAcl\Application\CircuitBreaker;
use App\Modules\ProviderAcl\Application\ResilientProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** TRIP-001 GeoNames selections: the server re-resolves catalog ids; browser metadata is never trusted. */
final class GeoNamesSelectionTrustTest extends TestCase
{
    private RecordingTripRequestService $tripRequests;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
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
        $this->tripRequests = new RecordingTripRequestService;
        $this->app->instance(TripRequestService::class, $this->tripRequests);
    }

    #[Test]
    public function matching_catalog_entries_are_accepted_after_server_resolution(): void
    {
        $this->fakeCatalog();

        $this->post('/planla', $this->form())->assertRedirect('/planlar/trip-ui-1');

        self::assertSame(['kind' => 'city', 'value' => 'geonames:745044', 'iana_timezone' => 'Europe/Istanbul'], $this->tripRequests->createdInput['origin_place']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'getJSON?geonameId=745044'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'getJSON?geonameId=3169070'));
    }

    #[Test]
    public function fabricated_timezone_kind_or_unknown_ids_are_rejected_in_turkish(): void
    {
        $this->fakeCatalog();
        $cases = [
            'timezone' => ['origin_timezone' => 'Asia/Tokyo'],
            'kind' => ['origin_kind' => 'airport'],
            'unknown id' => ['origin_value' => 'geonames:999'],
        ];

        foreach ($cases as $name => $override) {
            $this->from('/')->post('/planla', $override + $this->form())
                ->assertRedirect('/')
                ->assertSessionHasErrors(['origin_value' => 'Seçilen başlangıç konumu doğrulanamadı; listeden yeniden seçin.']);
        }
        self::assertNull($this->tripRequests->createdInput);
    }

    #[Test]
    public function provider_failure_and_unavailable_catalog_never_accept_unverified_catalog_ids(): void
    {
        Http::fake(['secure.geonames.org/*' => Http::response('<html>upstream test-user</html>', 503)]);
        $this->from('/')->post('/planla', $this->form())
            ->assertSessionHasErrors(['origin_value' => 'Seçilen başlangıç konumu şu anda doğrulanamıyor; lütfen biraz sonra yeniden deneyin.']);
        self::assertStringNotContainsString('test-user', json_encode(session('errors')->all(), JSON_THROW_ON_ERROR));

        config(['services.geonames.username' => '']);
        $this->from('/')->post('/planla', $this->form())
            ->assertSessionHasErrors(['destination_value' => 'Konum kataloğu şu anda kullanılamadığından seçilen varış konumu doğrulanamadı.']);
        self::assertNull($this->tripRequests->createdInput);
    }

    #[Test]
    public function attribution_is_shown_only_while_geonames_drives_the_planner(): void
    {
        $this->get('/')->assertOk()->assertSee('https://www.geonames.org/', false)->assertSee('CC BY 4.0');

        config(['services.duffel.access_token' => 'token', 'provider_resilience.providers.duffel_places' => config('provider_resilience.providers.geonames')]);
        $this->get('/')->assertOk()->assertDontSee('https://www.geonames.org/', false);

        config(['services.duffel.access_token' => '', 'services.geonames.username' => '']);
        $this->get('/')->assertOk()->assertDontSee('https://www.geonames.org/', false)->assertSee('Ülkeyi seçebilirsiniz; ancak şehir ve havalimanı araması şu anda kullanılamıyor.');
    }

    private function fakeCatalog(): void
    {
        Http::fake(['secure.geonames.org/getJSON*' => function (Request $request) {
            return match (true) {
                str_contains($request->url(), 'geonameId=745044') => Http::response($this->row(745044, 'İstanbul', 'PPLA', 'Europe/Istanbul')),
                str_contains($request->url(), 'geonameId=3169070') => Http::response($this->row(3169070, 'Roma', 'PPLC', 'Europe/Rome')),
                default => Http::response(['status' => ['message' => 'no result', 'value' => 15]]),
            };
        }]);
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $name, string $featureCode, string $timezone): array
    {
        return ['geonameId' => $id, 'name' => $name, 'countryCode' => 'TR', 'fcode' => $featureCode, 'adminName1' => $name,
            'lat' => '41.0', 'lng' => '28.9', 'timezone' => ['timeZoneId' => $timezone, 'gmtOffset' => 3]];
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'origin_kind' => 'city', 'origin_value' => 'geonames:745044', 'origin_timezone' => 'Europe/Istanbul',
            'destination_kind' => 'city', 'destination_value' => 'geonames:3169070', 'destination_timezone' => 'Europe/Rome',
            'journey_type' => 'ONE_WAY', 'outbound_date_mode' => 'exact', 'outbound_exact_date' => '2026-10-15', 'traveler_count' => '1',
        ];
    }
}
