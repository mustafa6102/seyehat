<?php

namespace Tests\Feature;

use App\Modules\ProviderAcl\Contracts\AviationLocationCatalog;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** PLT-REL-001 / CFG-RETRY-TIMEOUT-CIRCUIT: the deploy-time duffel_places profile from config (no overrides). */
final class DuffelPlacesResilienceTest extends TestCase
{
    private const TOKEN = 'duffel_live_secret_value';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = [$event->message, $event->context];
        });
    }

    #[Test]
    public function the_versioned_profile_is_complete_and_stays_below_documented_provider_limits(): void
    {
        $policy = $this->app->make(ResiliencePolicies::class)->for('duffel_places');

        self::assertNotNull($policy);
        self::assertSame(['duffel-places-ops-2026-09-29', 2000, 2, 200, 1000, 5, 60, 50, 60], [
            $policy->version, $policy->timeoutMs, $policy->maxAttempts, $policy->baseDelayMs, $policy->maxDelayMs,
            $policy->failureThreshold, $policy->openSeconds, $policy->rateLimitMaxRequests, $policy->rateLimitPerSeconds,
        ]);
        // Lowest documented Duffel limit: 60 requests per 60 seconds.
        self::assertLessThan(60 / 60, $policy->rateLimitMaxRequests / $policy->rateLimitPerSeconds);
    }

    #[Test]
    public function the_catalog_is_enabled_only_when_the_credential_is_present(): void
    {
        Http::fake();
        $catalog = fn (): AviationLocationCatalog => $this->app->make(AviationLocationCatalog::class);

        config(['services.duffel.access_token' => '']);
        self::assertFalse($catalog()->isAvailable());
        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)
            ->assertJsonPath('error.message_tr', 'Havalimanı ve şehir araması henüz kullanıma açılmadı.');
        $this->get('/')->assertOk()->assertDontSee('baglam=ucus', false);
        Http::assertNothingSent();

        config(['services.duffel.access_token' => self::TOKEN]);
        self::assertTrue($catalog()->isAvailable());
        $this->get('/')->assertOk()->assertSee('baglam=ucus', false)->assertDontSee(self::TOKEN);
    }

    #[Test]
    public function timeout_is_retried_once_then_reported_in_turkish(): void
    {
        config(['services.duffel.access_token' => self::TOKEN]);
        Http::fake(['api.duffel.com/*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out')
            ->pushFailedConnection('cURL error 28: Operation timed out')]);

        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)
            ->assertJsonPath('error.code', 'PROVIDER_TIMEOUT')
            ->assertJsonPath('error.message_tr', 'Sağlayıcı yanıtı zamanında alınamadı. Havalimanı ve şehir araması şu anda yapılamıyor.');
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_transient_server_error_is_retried_and_the_second_attempt_is_used(): void
    {
        config(['services.duffel.access_token' => self::TOKEN]);
        Http::fake(['api.duffel.com/*' => Http::sequence()
            ->push('<html>upstream</html>', 503)
            ->push(['data' => [['type' => 'airport', 'id' => 'arp_ist_tr', 'iata_code' => 'IST', 'iata_country_code' => 'TR', 'name' => 'Istanbul Airport', 'city_name' => 'Istanbul', 'time_zone' => 'Europe/Istanbul']]])]);

        $locations = $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertOk()->json('locations');

        Http::assertSentCount(2);
        self::assertSame(['kind', 'label', 'country_code', 'iana_timezone', 'aviation_identity', 'selection'], array_keys($locations[0]));
        self::assertSame(['type' => 'IATA_AIRPORT', 'code' => 'IST'], $locations[0]['aviation_identity']);
    }

    #[Test]
    public function rejected_credentials_and_other_client_errors_are_not_retried(): void
    {
        config(['services.duffel.access_token' => self::TOKEN]);

        foreach ([401, 403, 400, 422] as $status) {
            Http::fake(['api.duffel.com/*' => Http::response(['errors' => [['message' => 'invalid token '.self::TOKEN]]], $status)]);

            $response = $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)
                ->assertJsonPath('error.code', null)
                ->assertJsonPath('error.message_tr', 'Sağlayıcıya şu anda ulaşılamıyor. Havalimanı ve şehir araması şu anda yapılamıyor.');
            Http::assertSentCount(1);
            self::assertStringNotContainsString(self::TOKEN, $response->getContent());
            self::assertStringNotContainsString('invalid token', $response->getContent());
        }
    }

    #[Test]
    public function provider_429_is_not_retried(): void
    {
        config(['services.duffel.access_token' => self::TOKEN]);
        Http::fake(['api.duffel.com/*' => Http::response(['errors' => [['type' => 'rate_limit_error']]], 429, ['ratelimit-reset' => 'Tue, 29 Sep 2026 10:01:00 GMT'])]);

        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)
            ->assertJsonPath('error.code', 'PROVIDER_RATE_LIMITED');
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_circuit_opens_after_the_failure_threshold_and_stops_calling_the_provider(): void
    {
        config(['services.duffel.access_token' => self::TOKEN]);
        $healthy = false;
        Http::fake(['api.duffel.com/*' => function () use (&$healthy) {
            return $healthy ? Http::response(['data' => []]) : Http::response('<html>down</html>', 503);
        }]);

        // 2 attempts per call: calls 1-2 record 4 failures, call 3 records the 5th and opens the circuit.
        foreach (range(1, 3) as $call) {
            $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503);
        }
        Http::assertSentCount(5);

        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)
            ->assertJsonPath('error.message_tr', 'Sağlayıcıya şu anda ulaşılamıyor. Havalimanı ve şehir araması şu anda yapılamıyor.');
        Http::assertSentCount(5);

        // After the documented 60 s window the circuit allows a probe again.
        $this->travel(61)->seconds();
        $healthy = true;
        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertOk()->assertExactJson(['locations' => []]);
    }

    #[Test]
    public function the_local_rate_limit_stops_calls_before_the_provider_limit(): void
    {
        config(['services.duffel.access_token' => self::TOKEN]);
        Http::fake(['api.duffel.com/*' => Http::response(['data' => []])]);

        foreach (range(1, 50) as $call) {
            $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertOk();
        }
        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)
            ->assertJsonPath('error.code', 'PROVIDER_RATE_LIMITED')
            ->assertJsonPath('error.message_tr', 'Sağlayıcı istek sınırı nedeniyle geçici olarak yanıt veremedi. Havalimanı ve şehir araması şu anda yapılamıyor.');
        Http::assertSentCount(50);
    }

    #[Test]
    public function the_credential_is_sent_only_to_duffel_and_never_appears_in_responses_or_logs(): void
    {
        config(['services.duffel.access_token' => self::TOKEN]);
        Http::fake(['api.duffel.com/*' => Http::sequence()
            ->pushFailedConnection('cURL error 7: failed to connect to api.duffel.com token='.self::TOKEN)
            ->push('<html>'.self::TOKEN.'</html>', 500)]);

        $response = $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.duffel.com/places/suggestions?')
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN) && $request->method() === 'GET');
        self::assertNotSame([], $this->logged);
        foreach ([$response->getContent(), json_encode($this->logged, JSON_THROW_ON_ERROR), $this->get('/')->getContent()] as $text) {
            self::assertStringNotContainsString(self::TOKEN, $text);
            self::assertStringNotContainsString('cURL', $text);
        }
    }
}
