<?php

namespace Tests\Feature;

use App\Modules\Accommodation\Infrastructure\Queue\RunAccommodationSearch;
use App\Modules\Api\Domain\TripRequest\LocationRef;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Infrastructure\Persistence\DatabaseTripRequestRepository;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use App\Modules\Authorization\Infrastructure\Web\SessionWebPrincipalResolver;
use App\Modules\FlightSearch\Domain\FlightSearchJobRecord;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use App\Modules\FlightSearch\Infrastructure\Queue\RunFlightSearch;
use App\Modules\Poi\Infrastructure\Queue\RunPoiDiscovery;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\Fakes\FixedWebPrincipalResolver;
use Tests\TestCase;

/**
 * v3.16 PLT-AUTH-001 retrofit (od_impact ADR-010 blocked_pending_auth_task; RULE-PLT-AUTH-001-04..06, AC/TS-PLT-AUTH-001-04..06):
 * OP-getTripRequest, flight/accommodation/POI search start+poll and flight/accommodation selection read/save/reconfirm
 * require bearerAuth and the TripRequest owner. Real TripRequestOwnershipGuard over in-memory SQLite; Http and Queue are
 * faked, so no Duffel, Overpass or IdP call can happen. The same checks run on the OD-028 (v3.17) web channel /web-api/v1
 * (signed-in browser session + CSRF, same handlers), which serves every operation above except OP-getTripRequest.
 */
final class TripRequestOwnedOperationsAuthorizationTest extends TestCase
{
    private const SECRET = 'GIZLI-ICERIK';

    private const VERIFIED_AT = '2026-10-17T10:00:00+00:00';

    private string $owned;

    private string $foreign;

    private string $legacy;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Http::fake();
        Queue::fake();

        $repository = new DatabaseTripRequestRepository(DB::connection());
        $this->owned = $repository->save($this->tripRequest()->ownedBy((new FixedBearerAuthenticator)->principal))->id;
        $this->foreign = $repository->save($this->tripRequest()->ownedBy(new PrincipalRef('https://issuer.test', 'someone-else')))->id;
        $this->legacy = $repository->save($this->tripRequest())->id;
        foreach ([$this->owned, $this->foreign, $this->legacy] as $id) {
            $this->storeSelection('trip_request_flight_selections', $id);
            $this->storeSelection('trip_request_accommodation_selections', $id);
        }
    }

    #[Test]
    public function every_trip_request_owned_operation_requires_a_valid_bearer_token_and_ignores_the_web_session(): void
    {
        $this->storeJobs();
        // TestCase binds a web principal; a browser session alone must never authenticate /api/v1.
        $this->withSession(['auth.principal' => ['iss' => 'https://issuer.test', 'sub' => 'api-user']]);

        foreach ($this->operations($this->owned) as $name => [$method, $uri, $body, $headers]) {
            foreach ([[], ['Authorization' => 'Bearer invalid-token'], ['Authorization' => 'Basic dXNlcjpwYXNz']] as $auth) {
                $response = $this->json($method, $uri, $body, $headers + $auth);
                $response->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer');
                self::assertSame('AUTHENTICATION_REQUIRED', $response->json('error.code'), $name);
                self::assertStringNotContainsString(self::SECRET, (string) $response->getContent(), $name);
            }
        }
        // 401 precedes 404 for an unknown TripRequest.
        $this->getJson('/api/v1/trip-requests/999999')->assertStatus(401);
        $this->assertNoSideEffects();
    }

    #[Test]
    #[DataProvider('channels')]
    public function another_principals_or_an_ownerless_legacy_trip_request_is_403_without_content_or_side_effects(string $channel): void
    {
        $this->storeJobs();
        $prefix = $this->signIn($channel);

        foreach (['foreign' => $this->foreign, 'legacy' => $this->legacy] as $label => $tripRequestId) {
            foreach ($this->operations($tripRequestId, $prefix) as $name => [$method, $uri, $body, $headers]) {
                $response = $this->json($method, $uri, $body, $headers);
                $response->assertStatus(403);
                self::assertSame('AUTHORIZATION_DENIED', $response->json('error.code'), "$label $name");
                self::assertStringNotContainsString(self::SECRET, (string) $response->getContent(), "$label $name");
                self::assertNull($response->json('status'), "$label $name");
            }
        }
        $this->assertNoSideEffects();
        // Legacy rows are never claimed by the caller (RULE-PLT-AUTH-001-06).
        self::assertNull(DB::table('trip_requests')->where('id', $this->legacy)->value('owner_subject'));
        // A refused poll must not move the (already expired) job through its expiry transition either.
        self::assertSame('QUEUED', DB::table('flight_search_jobs')->where('search_id', 'flight-'.$this->foreign)->value('status'));
    }

    #[Test]
    #[DataProvider('channels')]
    public function an_unknown_trip_request_or_search_is_404(string $channel): void
    {
        $prefix = $this->signIn($channel);

        foreach ($this->operations('999999', $prefix) as $name => [$method, $uri, $body, $headers]) {
            $response = $this->json($method, $uri, $body, $headers);
            $response->assertStatus(404);
            self::assertSame('RESOURCE_NOT_FOUND', $response->json('error.code'), $name);
        }
        $this->assertNoSideEffects();
    }

    #[Test]
    #[DataProvider('channels')]
    public function a_job_without_a_trustworthy_trip_request_relation_is_denied(string $channel): void
    {
        $jobs = $this->app->make(FlightSearchJobRepository::class);
        // Pre-v3.4 flight job (trip_request_id NULL) and jobs whose TripRequest no longer exists: no owner can be derived.
        $jobs->save(new FlightSearchJobRecord('flight-legacy', 'seed-legacy', 'COMPLETED', ['offers' => [['name' => self::SECRET]]], null, null, null, null));
        $jobs->save(new FlightSearchJobRecord('flight-orphan', 'seed-orphan', 'COMPLETED', ['offers' => [['name' => self::SECRET]]], null, null, null, '424242'));
        $this->insertJob('accommodation_search_jobs', 'accommodation-orphan', '424242');
        $this->insertJob('poi_discovery_jobs', 'poi-orphan', '424242');
        $prefix = $this->signIn($channel);

        foreach (['flight-search-jobs/flight-legacy', 'flight-search-jobs/flight-orphan', 'accommodation-search-jobs/accommodation-orphan', 'poi-discovery-jobs/poi-orphan'] as $path) {
            $response = $this->getJson("$prefix/$path")
                ->assertStatus(403)->assertJsonPath('error.code', 'AUTHORIZATION_DENIED');
            self::assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function the_owner_proceeds_to_the_unchanged_operation_behavior(string $channel): void
    {
        $this->storeJobs();
        $prefix = $this->signIn($channel);

        if ($channel === 'api') {
            $this->getJson("/api/v1/trip-requests/{$this->owned}")->assertOk()->assertJsonPath('status', 'SEARCHABLE');
        }
        foreach (['flight-search-jobs/flight-', 'accommodation-search-jobs/accommodation-', 'poi-discovery-jobs/poi-'] as $path) {
            $this->getJson("$prefix/$path{$this->owned}")->assertOk()->assertSee(self::SECRET);
        }
        foreach (['flight-selection', 'accommodation-selection'] as $selection) {
            $this->getJson("$prefix/trip-requests/{$this->owned}/$selection")->assertOk()
                ->assertJsonPath('status', 'CURRENT')->assertJsonPath('offer.name', self::SECRET);
            // Existing business rules answer: unknown offer -> 404, a CURRENT selection cannot be reconfirmed -> 409.
            $this->putJson("$prefix/trip-requests/{$this->owned}/$selection", ['offer_id' => 'missing-offer', 'expected_verified_at' => self::VERIFIED_AT], ['Idempotency-Key' => "own-$selection"])
                ->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
            $this->postJson("$prefix/trip-requests/{$this->owned}/$selection/reconfirm", ['expected_verified_at' => self::VERIFIED_AT], ['Idempotency-Key' => "own-re-$selection"])
                ->assertStatus(409)->assertJsonPath('error.code', 'OFFER_NOT_SELECTABLE');
        }

        // Start: an Idempotency-Key a non-owner was refused with stays free, since nothing was recorded for it.
        foreach ($this->startUris($prefix) as $uri) {
            $this->postJson($uri, ['trip_request_id' => $this->foreign], ['Idempotency-Key' => 'shared-key'])->assertStatus(403);
        }
        Queue::assertNothingPushed();
        foreach ($this->startUris($prefix) as $uri) {
            $this->postJson($uri, ['trip_request_id' => $this->owned], ['Idempotency-Key' => 'shared-key'])->assertStatus(202)->assertJsonPath('status', 'QUEUED');
        }
        Queue::assertPushed(RunFlightSearch::class, 1);
        Queue::assertPushed(RunAccommodationSearch::class, 1);
        Queue::assertPushed(RunPoiDiscovery::class, 1);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_web_channel_requires_a_signed_in_session_and_never_accepts_a_bearer_token(): void
    {
        $this->storeJobs();
        $this->app->instance(WebPrincipalResolver::class, new FixedWebPrincipalResolver(null));

        foreach ($this->operations($this->owned, '/web-api/v1') as $name => [$method, $uri, $body, $headers]) {
            foreach ([[], ['Authorization' => 'Bearer '.FixedBearerAuthenticator::TOKEN]] as $auth) {
                $response = $this->json($method, $uri, $body, $headers + $auth)->assertStatus(401);
                self::assertSame('AUTHENTICATION_REQUIRED', $response->json('error.code'), $name);
                self::assertSame('Bu işlem için oturum açmanız gerekiyor.', $response->json('error.message_tr'), $name);
                self::assertStringNotContainsString(self::SECRET, (string) $response->getContent(), $name);
            }
        }
        $this->assertNoSideEffects();
    }

    #[Test]
    public function the_web_channel_principal_comes_only_from_the_completed_oidc_login_session(): void
    {
        config(['services.oidc.issuer' => 'https://issuer.test']);
        $this->app->instance(WebPrincipalResolver::class, new SessionWebPrincipalResolver);
        $owner = (new FixedBearerAuthenticator)->principal;

        $this->getJson("/web-api/v1/trip-requests/{$this->owned}/flight-selection")->assertStatus(401);
        $this->withSession([SessionWebPrincipalResolver::SESSION_KEY => ['issuer' => $owner->issuer, 'subject' => $owner->subject]]);
        $this->getJson("/web-api/v1/trip-requests/{$this->owned}/flight-selection")->assertOk()->assertJsonPath('offer.name', self::SECRET);
        $response = $this->getJson("/web-api/v1/trip-requests/{$this->foreign}/flight-selection")->assertStatus(403);
        // Neither the principal nor owner fields reach the browser.
        foreach (['api-user', 'issuer.test', 'owner_issuer', 'owner_subject', 'someone-else'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $response->getContent());
        }
    }

    #[Test]
    public function web_channel_mutations_are_csrf_protected_before_any_side_effect(): void
    {
        $webGroup = app(Kernel::class)->getMiddlewareGroups()['web'];
        self::assertContains(ValidateCsrfToken::class, $webGroup);
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (str_starts_with($route->uri(), 'web-api/v1/')) {
                self::assertContains('web', $middleware, $route->uri());
                self::assertContains('auth.web-session', $middleware, $route->uri());
                self::assertNotContains('auth.bearer', $middleware, $route->uri());
                self::assertNotContains(ValidateCsrfToken::class, $route->excludedMiddleware(), $route->uri());
            }
            if (str_starts_with($route->uri(), 'api/')) {
                self::assertNotContains('auth.web-session', $middleware, $route->uri());
                self::assertNotContains('web', $middleware, $route->uri());
            }
        }

        // Real CSRF enforcement (Laravel skips it in unit tests unless told otherwise).
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            public function __construct(Application $app, Encrypter $encrypter)
            {
                parent::__construct($app, $encrypter);
            }

            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->signIn('web');
        $this->withSession(['_token' => 'csrf-test-token']);
        foreach ($this->operations($this->owned, '/web-api/v1') as $name => [$method, $uri, $body, $headers]) {
            if ($method === 'GET') {
                continue;
            }
            $response = $this->json($method, $uri, $body, $headers)->assertStatus(419);
            self::assertSame('Oturum doğrulaması geçersiz veya süresi doldu; sayfayı yenileyip yeniden deneyin.', $response->json('error.message_tr'), $name);
        }
        $this->assertNoSideEffects();

        $this->postJson('/web-api/v1/flight-search-jobs', ['trip_request_id' => $this->owned], ['Idempotency-Key' => 'csrf-ok', 'X-CSRF-TOKEN' => 'csrf-test-token'])
            ->assertStatus(202);
        Queue::assertPushed(RunFlightSearch::class, 1);
    }

    #[Test]
    public function offer_and_standalone_poi_operations_stay_outside_trip_request_ownership(): void
    {
        // od_impact ADR-010 unchanged_not_trip_request_owned: no bearer requirement was added.
        foreach ([['GET', '/api/v1/offers/none'], ['POST', '/api/v1/offers/none/reprice'], ['GET', '/api/v1/accommodation-offers/none'],
            ['POST', '/api/v1/accommodation-offers/none/revalidate'], ['GET', '/api/v1/pois/none'], ['POST', '/api/v1/pois/none/verify']] as [$method, $uri]) {
            self::assertNotContains($this->json($method, $uri)->getStatusCode(), [401, 403], $uri);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, string>}> */
    private function operations(string $tripRequestId, string $prefix = '/api/v1'): array
    {
        $select = ['offer_id' => 'offer-1', 'expected_verified_at' => self::VERIFIED_AT];
        $reconfirm = ['expected_verified_at' => self::VERIFIED_AT];

        $operations = [
            'OP-getTripRequest' => ['GET', "{$prefix}/trip-requests/$tripRequestId", [], []],
            'OP-startFlightSearch' => ['POST', "{$prefix}/flight-search-jobs", ['trip_request_id' => $tripRequestId], ['Idempotency-Key' => "fs-$tripRequestId"]],
            'OP-pollFlightSearch' => ['GET', "{$prefix}/flight-search-jobs/flight-$tripRequestId", [], []],
            'OP-getTripRequestFlightSelection' => ['GET', "{$prefix}/trip-requests/$tripRequestId/flight-selection", [], []],
            'OP-selectTripRequestFlight' => ['PUT', "{$prefix}/trip-requests/$tripRequestId/flight-selection", $select, ['Idempotency-Key' => "fsel-$tripRequestId"]],
            'OP-reconfirmTripRequestFlight' => ['POST', "{$prefix}/trip-requests/$tripRequestId/flight-selection/reconfirm", $reconfirm, ['Idempotency-Key' => "fre-$tripRequestId"]],
            'OP-startAccommodationSearch' => ['POST', "{$prefix}/accommodation-search-jobs", ['trip_request_id' => $tripRequestId], ['Idempotency-Key' => "as-$tripRequestId"]],
            'OP-pollAccommodationSearch' => ['GET', "{$prefix}/accommodation-search-jobs/accommodation-$tripRequestId", [], []],
            'OP-getTripRequestAccommodationSelection' => ['GET', "{$prefix}/trip-requests/$tripRequestId/accommodation-selection", [], []],
            'OP-selectTripRequestAccommodation' => ['PUT', "{$prefix}/trip-requests/$tripRequestId/accommodation-selection", $select, ['Idempotency-Key' => "asel-$tripRequestId"]],
            'OP-reconfirmTripRequestAccommodation' => ['POST', "{$prefix}/trip-requests/$tripRequestId/accommodation-selection/reconfirm", $reconfirm, ['Idempotency-Key' => "are-$tripRequestId"]],
            'OP-startPoiDiscovery' => ['POST', "{$prefix}/poi-discovery-jobs", ['trip_request_id' => $tripRequestId], ['Idempotency-Key' => "poi-$tripRequestId"]],
            'OP-pollPoiDiscovery' => ['GET', "{$prefix}/poi-discovery-jobs/poi-$tripRequestId", [], []],
        ];
        // OP-getTripRequest is not called by the browser and has no web channel (OD-028).
        if ($prefix !== '/api/v1') {
            unset($operations['OP-getTripRequest']);
        }

        return $operations;
    }

    /** @return list<string> */
    private function startUris(string $prefix): array
    {
        return ["$prefix/flight-search-jobs", "$prefix/accommodation-search-jobs", "$prefix/poi-discovery-jobs"];
    }

    /** @return array<string, array{0: string}> */
    public static function channels(): array
    {
        return ['bearer API' => ['api'], 'OD-028 web session' => ['web']];
    }

    /** Authenticates as the owner of $this->owned on the channel and returns its path prefix. */
    private function signIn(string $channel): string
    {
        if ($channel === 'api') {
            $this->withToken(FixedBearerAuthenticator::TOKEN);

            return '/api/v1';
        }
        $this->app->instance(WebPrincipalResolver::class, new FixedWebPrincipalResolver((new FixedBearerAuthenticator)->principal));

        return '/web-api/v1';
    }

    /** No job, idempotency record, queued work, provider call or selection change after refused requests. */
    private function assertNoSideEffects(): void
    {
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        foreach (['flight_search_jobs', 'accommodation_search_jobs', 'poi_discovery_jobs'] as $table) {
            self::assertSame(0, DB::table($table)->where('idempotency_key', 'not like', 'seed-%')->count(), $table);
        }
        foreach (['trip_request_flight_selections', 'trip_request_accommodation_selections'] as $table) {
            self::assertSame(['CURRENT'], DB::table($table)->distinct()->pluck('status')->all(), $table);
            self::assertSame(['offer-seed'], DB::table($table)->distinct()->pluck('provider_offer_id')->all(), $table);
        }
        foreach (['flight-selection-idempotency:' => ['fsel', 'fre'], 'accommodation-selection-idempotency:' => ['asel', 'are']] as $cachePrefix => $keys) {
            foreach ($keys as $key) {
                foreach ([$this->owned, $this->foreign, $this->legacy, '999999'] as $id) {
                    self::assertFalse(Cache::has($cachePrefix.hash('sha256', "$key-$id")), "$cachePrefix$key-$id");
                }
            }
        }
    }

    private function storeJobs(): void
    {
        $jobs = $this->app->make(FlightSearchJobRepository::class);
        foreach ([$this->owned, $this->foreign, $this->legacy] as $id) {
            // Non-owner flight jobs are already past expires_at: only an authorized poll may move them to EXPIRED.
            $jobs->save($id === $this->owned
                ? new FlightSearchJobRecord("flight-$id", "seed-flight-$id", 'COMPLETED', ['offers' => [['name' => self::SECRET]]], null, null, null, $id)
                : new FlightSearchJobRecord("flight-$id", "seed-flight-$id", 'QUEUED', null, null, null, new DateTimeImmutable('2020-01-01T00:00:00+00:00'), $id));
            $this->insertJob('accommodation_search_jobs', "accommodation-$id", $id);
            $this->insertJob('poi_discovery_jobs', "poi-$id", $id);
        }
    }

    private function insertJob(string $table, string $searchId, string $tripRequestId): void
    {
        DB::table($table)->insert([
            'search_id' => $searchId, 'idempotency_key' => "seed-$searchId", 'trip_request_id' => $tripRequestId, 'status' => 'COMPLETED',
            'result_json' => json_encode(['items' => [['name' => self::SECRET]]]), 'error_code' => null, 'error_message_tr' => null,
            'expires_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function storeSelection(string $table, string $tripRequestId): void
    {
        DB::table($table)->insert([
            'trip_request_id' => $tripRequestId, 'status' => 'CURRENT', 'offer_snapshot' => json_encode(['name' => self::SECRET, 'provider_offer_id' => 'offer-seed']),
            'provider_id' => 'duffel_flights', 'provider_offer_id' => 'offer-seed', 'verified_price_minor_units' => 10000, 'verified_price_currency' => 'EUR',
            'verified_at' => '2026-10-17 10:00:00', 'expires_at' => '2099-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tripRequest(): TripRequest
    {
        $place = LocationRef::fromNullable(['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome']);

        return new TripRequest(null, 'SEARCHABLE', $place, $place, ['start_date' => '2026-11-01', 'end_date' => '2026-11-05'], 1, null);
    }
}
