<?php

namespace Tests\Feature;

use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\FlightSearch\Application\FlightOfferAggregator;
use App\Modules\FlightSearch\Application\LocationGatedFlightSearchExecutor;
use App\Modules\FlightSearch\Application\ResilientFlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightProviderAdapter;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightSearchOutcome;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightSearchJobRecord;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use App\Modules\FlightSearch\Domain\FlightSegment;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\FlightSearch\Domain\ProviderSource;
use App\Modules\FlightSearch\Domain\SegmentTime;
use App\Modules\FlightSearch\Infrastructure\Queue\RunFlightSearch;
use App\Modules\ProviderAcl\Application\CircuitBreaker;
use App\Modules\ProviderAcl\Application\ReliabilitySignals;
use App\Modules\ProviderAcl\Application\ResilientProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use Closure;
use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\Fakes\InMemoryFlightSearchJobRepository;
use Tests\Fakes\StaticTripRequestService;
use Tests\TestCase;

final class ProviderResilienceTest extends TestCase
{
    /** @var list<int> */
    private array $jitterCeilings = [];

    /** @var list<array<string, mixed>> */
    private array $logContexts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->app->instance(TripRequestService::class, new StaticTripRequestService);
        // Test-only policy values; production values are OPEN (CFG-RETRY-TIMEOUT-CIRCUIT).
        config(['provider_resilience.providers' => [
            'provider-a' => $this->policy(),
            'provider-b' => $this->policy(),
        ]]);
        $this->app->bind(ProviderCallGuard::class, fn ($app): ProviderCallGuard => new ResilientProviderCallGuard(
            $app->make(ResiliencePolicies::class),
            $app->make(CircuitBreaker::class),
            $app->make(RateLimiter::class),
            function (int $ceilingMs): int {
                $this->jitterCeilings[] = $ceilingMs;

                return $ceilingMs;
            },
        ));
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logContexts[] = ['message' => $event->message, ...$event->context];
        });
    }

    #[Test]
    public function ts_plt_rel_001_01_429_storm_opens_only_that_providers_circuit_and_labels_partial(): void
    {
        $limited = new ScriptedProviderAdapter('provider-a', fn (): array => throw ProviderFailure::rateLimited());
        $healthy = new ScriptedProviderAdapter('provider-b', fn (): array => [$this->offer('provider-b', 'B-1')]);

        $contracts = [];
        foreach (range(1, 5) as $run) {
            $contracts[] = $this->runSearch("storm-{$run}", [$limited, $healthy]);
        }

        // 429 is not retried in-call; after 3 failures the circuit short-circuits further calls.
        self::assertSame(3, $limited->calls);
        self::assertSame(5, $healthy->calls);
        Sleep::assertNeverSlept();
        foreach ($contracts as $run => $contract) {
            $this->assertJobContract($contract);
            self::assertSame('PARTIAL', $contract['status']);
            self::assertSame(['B-1'], $this->offerIds($contract));
            self::assertStringContainsString('Sonuçlar kısmidir', $contract['error']['message_tr']);
            if ($run < 3) {
                self::assertSame('PROVIDER_RATE_LIMITED', $contract['error']['code']);
            }
        }
        self::assertSame(['provider-a' => 'OPEN', 'provider-b' => 'CLOSED'], app(ReliabilitySignals::class)->circuitStates());
    }

    #[Test]
    public function ts_plt_rel_001_01_local_rate_limit_is_provider_scoped_and_not_charged_to_the_circuit(): void
    {
        config(['provider_resilience.providers.provider-b.rate_limit.max_requests' => 2]);
        $first = new ScriptedProviderAdapter('provider-a', fn (): array => [$this->offer('provider-a', 'A-1', 13000)]);
        $limited = new ScriptedProviderAdapter('provider-b', fn (): array => [$this->offer('provider-b', 'B-1')]);

        $contracts = array_map(fn (int $run): array => $this->runSearch("limit-{$run}", [$first, $limited]), [1, 2, 3]);

        self::assertSame(3, $first->calls);
        self::assertSame(2, $limited->calls);
        self::assertSame('COMPLETED', $contracts[1]['status']);
        self::assertSame('PARTIAL', $contracts[2]['status']);
        self::assertSame('PROVIDER_RATE_LIMITED', $contracts[2]['error']['code']);
        self::assertSame(['A-1'], $this->offerIds($contracts[2]));
        self::assertSame(['provider-a' => 'CLOSED', 'provider-b' => 'CLOSED'], app(ReliabilitySignals::class)->circuitStates());
    }

    #[Test]
    public function ts_plt_rel_001_02_partial_outage_retries_with_backoff_isolates_provider_and_recovers(): void
    {
        $healthyA = false;
        $flaky = new ScriptedProviderAdapter('provider-a', function () use (&$healthyA): array {
            if (! $healthyA) {
                throw new RuntimeException('HTTP 503 upstream body: api_key=sk-live-VENDORSECRET');
            }

            return [$this->offer('provider-a', 'A-1', 13000)];
        });
        $healthy = new ScriptedProviderAdapter('provider-b', fn (): array => [$this->offer('provider-b', 'B-1')]);

        $outage = $this->runSearch('outage-1', [$flaky, $healthy]);

        self::assertSame(3, $flaky->calls);
        self::assertSame([50, 50, 50], $flaky->timeouts);
        self::assertSame([100, 150], $this->jitterCeilings);
        Sleep::assertSleptTimes(2);
        $this->assertJobContract($outage);
        self::assertSame('PARTIAL', $outage['status']);
        self::assertNull($outage['error']['code']);
        self::assertSame('Sağlayıcıya şu anda ulaşılamıyor. Sonuçlar kısmidir; yalnız yanıt veren sağlayıcıların teklifleri gösteriliyor.', $outage['error']['message_tr']);
        self::assertSame(['B-1'], $this->offerIds($outage));
        foreach ([json_encode($outage, JSON_THROW_ON_ERROR), json_encode($this->logContexts, JSON_THROW_ON_ERROR)] as $exposed) {
            self::assertStringNotContainsString('VENDORSECRET', $exposed);
            self::assertStringNotContainsString('upstream', $exposed);
        }

        $whileOpen = $this->runSearch('outage-2', [$flaky, $healthy]);
        self::assertSame(3, $flaky->calls);
        self::assertSame('PARTIAL', $whileOpen['status']);

        $healthyA = true;
        $this->travel(31)->seconds();
        $recovered = $this->runSearch('outage-3', [$flaky, $healthy]);

        self::assertSame(4, $flaky->calls);
        self::assertSame('COMPLETED', $recovered['status']);
        self::assertNull($recovered['error']);
        self::assertSame(['B-1', 'A-1'], $this->offerIds($recovered));
        self::assertSame('CLOSED', app(ReliabilitySignals::class)->circuitStates()['provider-a']);
    }

    #[Test]
    public function ts_plt_rel_001_03_timeout_yields_labelled_partial_without_fabricated_or_late_data(): void
    {
        config(['provider_resilience.providers.provider-a.retry.max_attempts' => 1]);
        $late = new ScriptedProviderAdapter('provider-a', function (): array {
            usleep(80_000);

            return [$this->offer('provider-a', 'A-LATE', 9000)];
        });
        $healthy = new ScriptedProviderAdapter('provider-b', fn (): array => [$this->offer('provider-b', 'B-1')]);

        $partial = $this->runSearch('timeout-1', [$late, $healthy]);

        $this->assertJobContract($partial);
        self::assertSame('PARTIAL', $partial['status']);
        self::assertSame('PROVIDER_TIMEOUT', $partial['error']['code']);
        self::assertSame('Sağlayıcı yanıtı zamanında alınamadı. Sonuçlar kısmidir; yalnız yanıt veren sağlayıcıların teklifleri gösteriliyor.', $partial['error']['message_tr']);
        self::assertSame(['B-1'], $this->offerIds($partial));

        $timingOut = new ScriptedProviderAdapter('provider-a', fn (): array => throw ProviderFailure::timeout());
        $failed = $this->runSearch('timeout-2', [$timingOut]);

        $this->assertJobContract($failed);
        self::assertSame('FAILED', $failed['status']);
        self::assertNull($failed['result']);
        self::assertSame('PROVIDER_TIMEOUT', $failed['error']['code']);
        self::assertSame('Sağlayıcı yanıtı zamanında alınamadı. Uçuş araması tamamlanamadı. Lütfen daha sonra yeniden deneyin.', $failed['error']['message_tr']);
    }

    #[Test]
    public function ts_plt_rel_001_04_dlq_replay_is_idempotent_and_never_duplicates_mutations(): void
    {
        config(['queue.failed' => ['driver' => 'file', 'path' => tempnam(sys_get_temp_dir(), 'dlq')]]);
        $this->app->forgetInstance('queue.failer');
        $jobs = new InMemoryFlightSearchJobRepository;
        $executor = new CountingSearchExecutor(fn (): FlightSearchOutcome => FlightSearchOutcome::completed([$this->offer('provider-b', 'B-1')]));
        $this->app->instance(FlightSearchJobRepository::class, $jobs);
        $this->app->instance(FlightSearchExecutor::class, $executor);
        $signals = app(ReliabilitySignals::class);

        // A worker died mid-run: the message is dead-lettered twice (duplicate delivery), state still RUNNING.
        $jobs->save(new FlightSearchJobRecord('dlq-running', 'key-running', 'RUNNING', null, null, null, null, 'trip-1'));
        $this->deadLetter('dlq-running');
        $this->deadLetter('dlq-running');
        self::assertSame(2, $signals->dlqDepth());

        $this->replayDeadLetters();

        self::assertSame(1, $executor->calls);
        self::assertSame('COMPLETED', $jobs->find('dlq-running')?->status);
        $mutationsAfterReplay = $jobs->mutations;
        self::assertSame(0, $signals->dlqDepth());

        // A job that failed permanently: failed() marks it FAILED and replay must not revive or mutate it.
        $failing = new SyncJob($this->app, $this->payload('dlq-failed'), 'sync', 'default');
        $jobs->save(new FlightSearchJobRecord('dlq-failed', 'key-failed', 'RUNNING', null, null, null, null, 'trip-1'));
        $failing->fail(new RuntimeException('vendor stack trace'));
        $this->deadLetter('dlq-failed');
        $failedRecord = $jobs->find('dlq-failed');

        $this->replayDeadLetters();
        $this->deadLetter('dlq-running');
        $this->replayDeadLetters();

        self::assertSame(1, $executor->calls);
        self::assertSame($failedRecord, $jobs->find('dlq-failed'));
        self::assertSame('Uçuş araması tamamlanamadı. Lütfen yeniden deneyin.', $jobs->find('dlq-failed')?->errorMessageTr);
        self::assertSame($mutationsAfterReplay + 1, $jobs->mutations);
        self::assertSame(0, $signals->dlqDepth());
        self::assertStringNotContainsString('vendor stack trace', json_encode($this->logContexts, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function od_022_adapter_receives_untransformed_criteria_from_the_searchable_trip_request(): void
    {
        $healthy = new ScriptedProviderAdapter('provider-b', fn (): array => []);

        $this->runSearch('criteria-1', [$healthy]);

        self::assertSame([
            'trip_request_id' => 'trip-1',
            'origin_place' => ['kind' => 'city', 'value' => 'geonames:745044', 'iana_timezone' => 'Europe/Istanbul'],
            'destination_place' => ['kind' => 'airport', 'value' => 'geonames:6299784', 'iana_timezone' => 'Europe/Rome'],
            'journey_type' => 'ROUND_TRIP',
            'outbound_date' => ['type' => 'FLEXIBLE', 'start_date' => '2026-10-15', 'end_date' => '2026-10-20'],
            'return_date' => ['type' => 'EXACT', 'date' => '2026-10-27'],
            'traveler_count' => 2,
            'passenger_composition' => null,
        ], $healthy->criteria[0]->toContract());
    }

    #[Test]
    public function od_022_unrepresentable_locations_fail_before_any_provider_call(): void
    {
        $adapter = new ScriptedProviderAdapter('provider-a', fn (): array => [$this->offer('provider-a', 'A-1')]);
        $gated = new LocationGatedFlightSearchExecutor(new ResilientFlightSearchExecutor([$adapter], app(ProviderCallGuard::class), new FlightOfferAggregator));
        $jobs = new InMemoryFlightSearchJobRepository;
        $jobs->save(new FlightSearchJobRecord('gated-1', 'key-gated-1', 'QUEUED', null, null, null, null, 'trip-1'));

        (new RunFlightSearch('gated-1'))->handle($jobs, $gated);

        $record = $jobs->find('gated-1');
        self::assertSame(['FAILED', null, null], [$record->status, $record->errorCode, $record->result]);
        self::assertSame('Uçuş araması için başlangıç ve varış konumlarının havalimanı veya şehir aramasından seçilmiş olması gerekir; bu seyahat talebindeki konumların havacılık kimliği (IATA kodu) yok.', $record->errorMessageTr);
        self::assertSame(0, $adapter->calls);
        self::assertTrue($gated->isAvailable());
    }

    #[Test]
    public function od_022_unrepresentable_date_fails_before_any_provider_call_and_location_gate_is_unchanged(): void
    {
        $this->app->instance(TripRequestService::class, new class implements TripRequestService
        {
            public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
            {
                throw new RuntimeException('Not used.');
            }

            public function get(string $id): ?TripRequestView
            {
                $place = ['kind' => 'airport', 'value' => 'geonames:6299784', 'iana_timezone' => 'Europe/Istanbul'];

                // v3.9 round trip whose return ends before the outbound starts (RULE-TRIP-001-05).
                return new TripRequestView('SEARCHABLE', $place, $place, null, 1, null, 'ROUND_TRIP', ['type' => 'EXACT', 'date' => '2026-10-20'], ['type' => 'EXACT', 'date' => '2026-10-15']);
            }

            public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
            {
                return [];
            }
        });
        $adapter = new ScriptedProviderAdapter('provider-a', fn (): array => []);
        $gated = new LocationGatedFlightSearchExecutor(new ResilientFlightSearchExecutor([$adapter], app(ProviderCallGuard::class), new FlightOfferAggregator));
        $jobs = new InMemoryFlightSearchJobRepository;
        $jobs->save(new FlightSearchJobRecord('date-1', 'key-date-1', 'QUEUED', null, null, null, null, 'trip-inverted'));

        (new RunFlightSearch('date-1'))->handle($jobs, $gated);

        self::assertSame(['FAILED', null], [$jobs->find('date-1')->status, $jobs->find('date-1')->errorCode]);
        self::assertStringContainsString('yolculuk tipi veya tarih bilgisi uçuş araması için kullanılabilir biçimde değil', $jobs->find('date-1')->errorMessageTr);
        self::assertSame(0, $adapter->calls);
    }

    #[Test]
    public function od_022_jobs_without_searchable_trip_request_fail_safely_without_calling_providers(): void
    {
        $adapter = new ScriptedProviderAdapter('provider-a', fn (): array => [$this->offer('provider-a', 'A-1')]);
        $executor = new ResilientFlightSearchExecutor([$adapter], app(ProviderCallGuard::class), new FlightOfferAggregator);
        $cases = [
            ['legacy', null, null, 'Bu arama bir seyahat talebine bağlı olmadığı için yürütülemez. Lütfen yeni bir arama başlatın.'],
            ['draft', 'trip-draft', 'TRIP_REQUEST_INCOMPLETE', 'Seyahat talebinin zorunlu alanları eksik.'],
            ['missing', 'trip-unknown', 'RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.'],
        ];

        foreach ($cases as [$searchId, $tripRequestId, $code, $message]) {
            $jobs = new InMemoryFlightSearchJobRepository;
            $jobs->save(new FlightSearchJobRecord($searchId, 'key-'.$searchId, 'QUEUED', null, null, null, null, $tripRequestId));
            (new RunFlightSearch($searchId))->handle($jobs, $executor);

            self::assertSame(['FAILED', $code, $message, null], [$jobs->find($searchId)->status, $jobs->find($searchId)->errorCode, $jobs->find($searchId)->errorMessageTr, $jobs->find($searchId)->result]);
        }
        self::assertSame(0, $adapter->calls);
    }

    #[Test]
    public function malformed_canonical_trip_request_fails_before_any_provider_call_and_is_not_retried(): void
    {
        $this->app->instance(TripRequestService::class, new class implements TripRequestService
        {
            public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
            {
                throw new RuntimeException('Not used.');
            }

            public function get(string $id): ?TripRequestView
            {
                $place = ['kind' => 'airport', 'value' => 'x', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'IST']];

                // SEARCHABLE but without a traveler: not representable as SCH-FlightSearchCriteria.
                return new TripRequestView('SEARCHABLE', $place, $place, null, 0, null, 'ONE_WAY', ['type' => 'EXACT', 'date' => '2026-10-15']);
            }

            public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
            {
                return [];
            }
        });
        $adapter = new ScriptedProviderAdapter('provider-a', fn (): array => []);
        $gated = new LocationGatedFlightSearchExecutor(new ResilientFlightSearchExecutor([$adapter], app(ProviderCallGuard::class), new FlightOfferAggregator));
        $jobs = new InMemoryFlightSearchJobRepository;
        $jobs->save(new FlightSearchJobRecord('malformed-1', 'key-malformed-1', 'QUEUED', null, null, null, null, 'trip-malformed'));

        (new RunFlightSearch('malformed-1'))->handle($jobs, $gated);
        (new RunFlightSearch('malformed-1'))->handle($jobs, $gated);

        self::assertSame(['FAILED', null], [$jobs->find('malformed-1')->status, $jobs->find('malformed-1')->errorCode]);
        self::assertSame('Seyahat talebinin bilgileri uçuş arama kriterine dönüştürülemedi. Lütfen yeni bir seyahat talebi oluşturun.', $jobs->find('malformed-1')->errorMessageTr);
        self::assertSame(0, $adapter->calls);
    }

    #[Test]
    public function od_022_legacy_date_window_trip_request_fails_before_any_provider_call_without_reinterpretation(): void
    {
        $adapter = new ScriptedProviderAdapter('provider-a', fn (): array => []);
        $gated = new LocationGatedFlightSearchExecutor(new ResilientFlightSearchExecutor([$adapter], app(ProviderCallGuard::class), new FlightOfferAggregator));
        $jobs = new InMemoryFlightSearchJobRepository;
        $jobs->save(new FlightSearchJobRecord('legacy-1', 'key-legacy-1', 'QUEUED', null, null, null, null, 'trip-legacy'));

        (new RunFlightSearch('legacy-1'))->handle($jobs, $gated);

        // GAP-FLT-CRITERIA-001: no canonical reason code; the Turkish message names the legacy date model.
        self::assertSame(['FAILED', null, null], [$jobs->find('legacy-1')->status, $jobs->find('legacy-1')->errorCode, $jobs->find('legacy-1')->result]);
        self::assertStringContainsString('önceki tarih biçimiyle kaydedildi', $jobs->find('legacy-1')->errorMessageTr);
        self::assertSame(0, $adapter->calls);
        self::assertSame([], $adapter->criteria);
    }

    #[Test]
    public function all_provider_failure_fails_the_search_without_fabricated_offers_or_vendor_details(): void
    {
        $timeout = new ScriptedProviderAdapter('provider-a', fn (): array => throw ProviderFailure::timeout());
        $crash = new ScriptedProviderAdapter('provider-b', fn (): array => throw new RuntimeException('vendor stack trace token=secret-123'));

        $contract = $this->runSearch('all-down-1', [$timeout, $crash]);

        self::assertSame('FAILED', $contract['status']);
        self::assertNull($contract['result']);
        self::assertContains($contract['error']['code'], [null, 'PROVIDER_TIMEOUT']);
        self::assertNotSame('', $contract['error']['message_tr']);
        // Retryable failures are retried up to the configured attempts; nothing else is invented.
        self::assertSame(3, $timeout->calls);
        foreach ([json_encode($contract, JSON_THROW_ON_ERROR), json_encode($this->logContexts, JSON_THROW_ON_ERROR)] as $text) {
            self::assertStringNotContainsString('secret-123', $text);
            self::assertStringNotContainsString('vendor stack trace', $text);
        }
    }

    #[Test]
    public function offers_from_a_search_result_are_retrievable_for_detail_and_reprice(): void
    {
        $expiring = new DateTimeImmutable('+1 day');
        $healthy = new ScriptedProviderAdapter('provider-b', fn (): array => [$this->offer('provider-b', 'B-1', expiresAt: $expiring)]);

        $contract = $this->runSearch('persist-1', [$healthy]);

        self::assertSame('COMPLETED', $contract['status']);
        $this->getJson('/api/v1/offers/B-1')
            ->assertOk()
            ->assertJsonPath('provider_offer_id', 'B-1')
            ->assertJsonPath('price.completeness', 'COMPLETE');
    }

    #[Test]
    public function unconfigured_provider_is_never_called_and_search_is_not_fabricated(): void
    {
        config(['provider_resilience.providers' => []]);
        $adapter = new ScriptedProviderAdapter('provider-a', fn (): array => [$this->offer('provider-a', 'A-1')]);

        $contract = $this->runSearch('unconfigured-1', [$adapter]);

        self::assertSame(0, $adapter->calls);
        self::assertSame('FAILED', $contract['status']);
        self::assertNull($contract['result']);
        self::assertSame([], app(ReliabilitySignals::class)->circuitStates());
    }

    /** @param list<FlightProviderAdapter> $adapters @return array<string, mixed> */
    private function runSearch(string $searchId, array $adapters): array
    {
        $jobs = new InMemoryFlightSearchJobRepository;
        $jobs->save(new FlightSearchJobRecord($searchId, 'key-'.$searchId, 'QUEUED', null, null, null, null, 'trip-1'));
        $executor = new ResilientFlightSearchExecutor($adapters, app(ProviderCallGuard::class), new FlightOfferAggregator);

        (new RunFlightSearch($searchId))->handle($jobs, $executor);

        return $jobs->find($searchId)->toContract();
    }

    private function payload(string $searchId): string
    {
        $queue = $this->app['queue']->connection('sync');

        return (new ReflectionMethod($queue, 'createPayload'))->invoke($queue, new RunFlightSearch($searchId), 'default');
    }

    private function deadLetter(string $searchId): void
    {
        $this->app['queue.failer']->log('sync', 'default', $this->payload($searchId), new RuntimeException('dead-lettered'));
    }

    /** Replays every DLQ message through the regular worker path, then removes it (queue:retry semantics). */
    private function replayDeadLetters(): void
    {
        $failer = $this->app['queue.failer'];
        foreach ($failer->all() as $message) {
            (new SyncJob($this->app, $message->payload, 'sync', 'default'))->fire();
            $failer->forget($message->id);
        }
    }

    /** @param array<string, mixed> $contract */
    private function assertJobContract(array $contract): void
    {
        // SCH-FlightSearchJob v3.3: closed object, status enum, error {code, message_tr} | null.
        self::assertSame(['search_id', 'trip_request_id', 'status', 'result', 'error', 'expires_at'], array_keys($contract));
        self::assertContains($contract['status'], ['QUEUED', 'RUNNING', 'PARTIAL', 'COMPLETED', 'FAILED', 'EXPIRED']);
        if (in_array($contract['status'], ['PARTIAL', 'COMPLETED'], true)) {
            self::assertIsArray($contract['result']['offers']);
        }
        if ($contract['error'] !== null) {
            self::assertSame(['code', 'message_tr'], array_keys($contract['error']));
            self::assertContains($contract['error']['code'], [null, 'PROVIDER_TIMEOUT', 'PROVIDER_RATE_LIMITED']);
        }
    }

    /** @param array<string, mixed> $contract @return list<string> */
    private function offerIds(array $contract): array
    {
        return array_map(fn (array $offer): string => $offer['provider_offer_id'], $contract['result']['offers']);
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        return [
            'version' => 'test-1',
            'timeout_ms' => 50,
            'retry' => ['max_attempts' => 3, 'base_delay_ms' => 100, 'max_delay_ms' => 150],
            'circuit_breaker' => ['failure_threshold' => 3, 'open_seconds' => 30],
            'rate_limit' => ['max_requests' => 100, 'per_seconds' => 60],
        ];
    }

    private function offer(string $providerId, string $offerId, int $priceMinorUnits = 12000, ?DateTimeImmutable $expiresAt = null): FlightOffer
    {
        $fetchedAt = new DateTimeImmutable('2026-09-28T10:00:00+00:00');

        return new FlightOffer(
            providerOfferId: $offerId,
            price: new Money($priceMinorUnits, 'EUR'),
            priceCertainty: 'EXACT',
            priceCompleteness: 'COMPLETE',
            baggage: new BaggageAllowance(true, null, null, sourceReference: $providerId.':'.$offerId, fetchedAt: $fetchedAt, verifiedAt: $fetchedAt),
            expiresAt: $expiresAt,
            sources: [new ProviderSource($providerId, $offerId, 'AGGREGATOR', 'NDC', true, false, 'FRESH', 'UNSUPPORTED', $fetchedAt, $fetchedAt, null)],
            segments: [new FlightSegment(
                'TK',
                'TK'.$priceMinorUnits,
                ['TK'.$priceMinorUnits],
                'FULL_SERVICE',
                new SegmentTime('2026-10-25T11:00:00', 'Europe/Istanbul', new DateTimeImmutable('2026-10-25T08:00:00+00:00')),
                new SegmentTime('2026-10-25T12:30:00', 'Europe/Berlin', new DateTimeImmutable('2026-10-25T11:30:00+00:00')),
            )],
            bookable: false,
        );
    }
}

final class ScriptedProviderAdapter implements FlightProviderAdapter
{
    public int $calls = 0;

    /** @var list<int|null> */
    public array $timeouts = [];

    /** @var list<FlightSearchCriteria> */
    public array $criteria = [];

    public function __construct(private readonly string $id, private readonly Closure $behaviour) {}

    public function providerId(): string
    {
        return $this->id;
    }

    public function search(FlightSearchCriteria $criteria, ?int $timeoutMs = null): array
    {
        $this->calls++;
        $this->timeouts[] = $timeoutMs;
        $this->criteria[] = $criteria;

        return ($this->behaviour)();
    }
}

final class CountingSearchExecutor implements FlightSearchExecutor
{
    public int $calls = 0;

    public function __construct(private readonly Closure $outcome) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function execute(FlightSearchCriteria $criteria, callable $publishPartial): FlightSearchOutcome
    {
        $this->calls++;

        return ($this->outcome)();
    }
}
