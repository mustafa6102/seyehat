<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireProductionDiagnosticsAccess;
use App\Http\Support\AviationLocationSelection;
use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\ProviderAcl\Contracts\AviationIdentity;
use App\Modules\ProviderAcl\Contracts\LocationOption;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Tests\TestCase;

final class ProductionDiagnosticsTest extends TestCase
{
    private const TOKEN = 'diagnostics-header-secret';

    private string $recordPath;

    private string $singleLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordPath = storage_path('framework/testing/planla-diagnostic.json');
        $this->singleLogPath = storage_path('framework/testing/diagnostics-single.log');
        @unlink($this->recordPath);
        @unlink($this->singleLogPath);

        config([
            'diagnostics.enabled' => true,
            'diagnostics.token' => self::TOKEN,
            'diagnostics.record_path' => $this->recordPath,
            'logging.channels.single.path' => $this->singleLogPath,
            'services.duffel.access_token' => 'duffel_test_must_never_be_exposed',
            'database.connections.mysql.password' => 'db-password-must-never-be-exposed',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        ]);
        Log::forgetChannel('single');
        Log::forgetChannel(config('logging.default'));
    }

    protected function tearDown(): void
    {
        @unlink($this->recordPath);
        @unlink($this->singleLogPath);

        parent::tearDown();
    }

    #[Test]
    public function diagnostics_are_hidden_when_disabled_missing_or_unauthorized(): void
    {
        config(['diagnostics.enabled' => false]);
        $this->getJson('/internal/production-diagnostics', $this->headers())->assertNotFound();

        config(['diagnostics.enabled' => true]);
        $this->getJson('/internal/production-diagnostics')->assertNotFound();
        $this->getJson('/internal/production-diagnostics', $this->headers('wrong-token'))->assertNotFound();
    }

    #[Test]
    public function diagnostics_return_only_allowlisted_status_without_secrets(): void
    {
        $response = $this->getJson('/internal/production-diagnostics', $this->headers())->assertOk();

        $response->assertJsonStructure(['diagnostics' => [
            'app_environment', 'laravel_version', 'php_version', 'base_path',
            'log_default_channel', 'log_stack_channels', 'single_log_path',
            'storage_logs_exists', 'storage_logs_writable', 'laravel_log_exists', 'laravel_log_writable',
            'database_driver', 'database_connectivity', 'queue_driver', 'failed_job_count',
            'duffel_flights_search_capability', 'duffel_access_token', 'build_identifier',
        ], 'latest_planla_failure']);

        self::assertSame(
            ['diagnostics', 'latest_planla_failure'],
            array_keys($response->json()),
        );
        foreach ([self::TOKEN, 'duffel_test_must_never_be_exposed', 'db-password-must-never-be-exposed', config('app.key')] as $secret) {
            self::assertStringNotContainsString((string) $secret, $response->getContent());
        }
    }

    #[Test]
    public function logging_probe_reports_outcomes_without_exposing_the_header_token(): void
    {
        $response = $this->postJson('/internal/production-diagnostics/logging-probe', [], $this->headers())->assertOk();

        $response->assertJsonStructure(['correlation_id', 'normal_logger', 'single_logger']);
        self::assertContains($response->json('normal_logger'), ['OK', 'FAIL']);
        self::assertContains($response->json('single_logger'), ['OK', 'FAIL']);
        self::assertStringNotContainsString(self::TOKEN, $response->getContent());
        self::assertStringNotContainsString(self::TOKEN, (string) @file_get_contents($this->singleLogPath));
    }

    #[Test]
    public function unexpected_planla_failure_keeps_the_generic_page_and_records_safe_correlation(): void
    {
        $this->app->instance(TripRequestService::class, new class implements TripRequestService
        {
            public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
            {
                throw new RuntimeException('SQLSTATE secret-db-password provider-payload');
            }

            public function get(string $id): ?TripRequestView
            {
                return null;
            }

            public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
            {
                return [];
            }
        });

        $failure = $this->post('/planla', $this->validForm())
            ->assertStatus(500)
            ->assertSee('Beklenmeyen bir hata oluştu');
        foreach (['SQLSTATE', 'secret-db-password', 'provider-payload', 'RuntimeException'] as $leak) {
            self::assertStringNotContainsString($leak, $failure->getContent());
        }
        self::assertFileExists($this->recordPath);

        $record = $this->getJson('/internal/production-diagnostics', $this->headers())
            ->assertOk()
            ->json('latest_planla_failure');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $record['correlation_id']);
        self::assertSame('trip-planner.store', $record['route']);
        self::assertSame(RuntimeException::class, $record['exception_class']);
        self::assertMatchesRegularExpression('#^app/Http/Controllers/TripPlannerController\.php:[0-9]+$#', $record['application_frame']);
        self::assertArrayNotHasKey('message', $record);
        self::assertSame(
            ['correlation_id', 'timestamp', 'route', 'action', 'exception_class', 'application_frame'],
            array_keys($record),
        );
        self::assertStringNotContainsString('secret-db-password', json_encode($record, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function successful_planla_submission_is_unchanged(): void
    {
        $service = new RecordingDiagnosticTripRequestService;
        $this->app->instance(TripRequestService::class, $service);

        $this->post('/planla', $this->validForm())->assertRedirect('/planlar/diagnostic-trip-1');

        self::assertSame(1, $service->created['traveler_count']);
        self::assertSame(['adults' => 1, 'children' => 0, 'infants' => 0], $service->created['passenger_composition']);
        self::assertFileDoesNotExist($this->recordPath);
    }

    #[Test]
    public function logger_failure_fallback_contains_only_correlation_and_exception_class(): void
    {
        $errorLog = storage_path('framework/testing/diagnostics-php-error.log');
        @unlink($errorLog);
        $previousErrorLog = ini_get('error_log');
        ini_set('error_log', $errorLog);

        Log::extend('diagnostics-throwing', fn () => new class extends AbstractLogger
        {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger-secret');
            }
        });
        config([
            'logging.default' => 'diagnostics-broken',
            'logging.channels.diagnostics-broken' => ['driver' => 'diagnostics-throwing'],
        ]);
        Log::forgetChannel('diagnostics-broken');
        $this->app->instance(TripRequestService::class, new class implements TripRequestService
        {
            public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
            {
                throw new RuntimeException('request-secret');
            }

            public function get(string $id): ?TripRequestView
            {
                return null;
            }

            public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
            {
                return [];
            }
        });

        try {
            $this->post('/planla', $this->validForm())->assertStatus(500);
            $fallback = (string) file_get_contents($errorLog);
            self::assertStringContainsString('correlation_id', $fallback);
            self::assertStringContainsString(RuntimeException::class, $fallback);
            foreach (['logger-secret', 'request-secret', 'SQLSTATE', 'authorization', 'cookie'] as $leak) {
                self::assertStringNotContainsString($leak, $fallback);
            }
        } finally {
            ini_set('error_log', (string) $previousErrorLog);
            @unlink($errorLog);
        }
    }

    #[Test]
    public function exception_reporting_is_scoped_to_the_planla_store_route_only(): void
    {
        $events = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$events): void {
            $events[] = [$event->message, $event->context];
        });
        $failing = new class implements TripRequestService
        {
            public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
            {
                throw new RuntimeException('planla-secret');
            }

            public function get(string $id): ?TripRequestView
            {
                throw new RuntimeException('summary-secret');
            }

            public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
            {
                throw new RuntimeException('summary-secret');
            }
        };
        $this->app->instance(TripRequestService::class, $failing);

        // An unrelated route keeps Laravel's normal exception reporting (the exception is logged with its class).
        $this->get('/planlar/trip-1')->assertStatus(500);
        $normal = array_filter($events, fn (array $event): bool => isset($event[1]['exception']) && $event[1]['exception'] instanceof RuntimeException);
        self::assertNotEmpty($normal);
        self::assertFileDoesNotExist($this->recordPath);

        // POST /planla is recorded by the safe recorder and NOT reported with the raw exception.
        $events = [];
        $this->post('/planla', $this->validForm())->assertStatus(500);
        self::assertFileExists($this->recordPath);
        self::assertSame([], array_filter($events, fn (array $event): bool => isset($event[1]['exception'])));
        self::assertStringNotContainsString('planla-secret', (string) file_get_contents($this->recordPath));
    }

    #[Test]
    public function diagnostics_routes_use_no_session_cookie_or_csrf_middleware_and_set_no_cookies(): void
    {
        $router = $this->app->make('router');
        foreach ([['GET', 'internal/production-diagnostics'], ['POST', 'internal/production-diagnostics/logging-probe']] as [$method, $uri]) {
            $route = $router->getRoutes()->match(Request::create('/'.$uri, $method));
            $middleware = $router->gatherRouteMiddleware($route);

            self::assertContains(RequireProductionDiagnosticsAccess::class, $middleware);
            foreach ([ValidateCsrfToken::class, StartSession::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, ShareErrorsFromSession::class] as $removed) {
                self::assertNotContains($removed, $middleware, $uri);
            }
        }

        $response = $this->getJson('/internal/production-diagnostics', $this->headers())->assertOk();
        self::assertSame([], $response->baseResponse->headers->getCookies());
    }

    /** @return array<string, string> */
    private function headers(string $token = self::TOKEN): array
    {
        return ['X-Diagnostics-Token' => $token];
    }

    /** @return array<string, string> */
    private function validForm(): array
    {
        $selection = app(AviationLocationSelection::class);
        $origin = new LocationOption('duffel:arp_ist', 'airport', 'Istanbul Airport', 'TR', 'Europe/Istanbul', null, null, AviationIdentity::of('IATA_AIRPORT', 'IST'));
        $destination = new LocationOption('duffel:arp_lhr', 'airport', 'Heathrow Airport', 'GB', 'Europe/London', null, null, AviationIdentity::of('IATA_AIRPORT', 'LHR'));

        return [
            'origin_selection' => $selection->issue($origin),
            'destination_selection' => $selection->issue($destination),
            'journey_type' => 'ONE_WAY',
            'outbound_date_mode' => 'exact',
            'outbound_exact_date' => '2026-11-15',
            'traveler_count' => '1',
            'adults' => '1',
            'children' => '0',
            'infants' => '0',
        ];
    }
}

final class RecordingDiagnosticTripRequestService implements TripRequestService
{
    /** @var array<string, mixed> */
    public array $created = [];

    public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
    {
        $this->created = $input;

        return new CreatedTripRequest('diagnostic-trip-1', new TripRequestView(
            status: 'DRAFT',
            originPlace: $input['origin_place'],
            destinationPlace: $input['destination_place'],
            dateWindow: null,
            travelerCount: $input['traveler_count'],
            preferences: $input['preferences'],
            journeyType: $input['journey_type'],
            outboundDate: $input['outbound_date'],
            returnDate: $input['return_date'] ?? null,
            passengerComposition: $input['passenger_composition'],
        ));
    }

    public function get(string $id): ?TripRequestView
    {
        return null;
    }

    public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
    {
        return [];
    }
}
