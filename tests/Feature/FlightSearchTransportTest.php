<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\FlightSearch\Application\FlightSearchDispatcher;
use App\Modules\FlightSearch\Application\FlightSearchService;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightSearchOutcome;
use App\Modules\FlightSearch\Domain\FlightSearchJobRecord;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use App\Modules\FlightSearch\Infrastructure\Queue\RunFlightSearch;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fakes\InMemoryFlightSearchJobRepository;
use Tests\Fakes\StaticTripRequestService;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\Fakes\ServiceOwnedTripRequests;
use Tests\TestCase;

final class FlightSearchTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Flight flow, not authorization: the caller owns every TripRequest the bound service knows (v3.16 bearerAuth).
        $this->app->bind(TripRequestOwnership::class, ServiceOwnedTripRequests::class);
        $this->withToken(FixedBearerAuthenticator::TOKEN);
        $this->app->instance(TripRequestService::class, new StaticTripRequestService);
    }

    #[Test]
    public function start_is_idempotent_and_dispatches_one_logical_search(): void
    {
        $repository = new InMemoryFlightSearchJobRepository;
        $dispatcher = new RecordingSearchDispatcher;
        $this->app->instance(FlightSearchJobRepository::class, $repository);
        $this->app->instance(FlightSearchDispatcher::class, $dispatcher);

        $this->app->instance(TripRequestService::class, new StaticTripRequestService);

        $first = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => 'trip-1'], ['Idempotency-Key' => 'search-1'])->assertAccepted();
        $second = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => 'trip-1'], ['Idempotency-Key' => 'search-1'])->assertAccepted();

        self::assertSame($first->json('search_id'), $second->json('search_id'));
        self::assertSame('QUEUED', $first->json('status'));
        self::assertCount(1, $dispatcher->searchIds);
        $this->getJson('/api/v1/flight-search-jobs/'.$first->json('search_id'))
            ->assertOk()
            ->assertJsonPath('trip_request_id', 'trip-1');
    }

    #[Test]
    public function start_requires_an_existing_searchable_trip_request_and_rejects_key_reuse_for_another_trip(): void
    {
        $repository = new InMemoryFlightSearchJobRepository;
        $dispatcher = new RecordingSearchDispatcher;
        $this->app->instance(FlightSearchJobRepository::class, $repository);
        $this->app->instance(FlightSearchDispatcher::class, $dispatcher);
        $this->app->instance(TripRequestService::class, new StaticTripRequestService);
        $key = ['Idempotency-Key' => 'search-v34'];

        foreach ([[], ['trip_request_id' => ''], ['trip_request_id' => 5], ['trip_request_id' => 'trip-1', 'origin_place' => 'x']] as $body) {
            $this->postJson('/api/v1/flight-search-jobs', $body, $key)
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED');
        }
        $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => 'trip-unknown'], $key)
            ->assertNotFound()
            ->assertJsonPath('error.message_tr', 'Seyahat talebi bulunamadı.');
        $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => 'trip-draft'], $key)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRIP_REQUEST_INCOMPLETE')
            ->assertJsonPath('error.message_tr', 'Seyahat talebinin zorunlu alanları eksik.');
        self::assertSame([], $dispatcher->searchIds);

        $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => 'trip-1'], $key)->assertAccepted();
        $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => 'trip-2'], $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT')
            ->assertJsonPath('error.message_tr', 'Aynı idempotency anahtarı farklı bir istek için kullanılamaz.');
        self::assertCount(1, $dispatcher->searchIds);
    }

    #[Test]
    public function poll_is_safe_and_returns_partial_then_terminal_snapshot(): void
    {
        $repository = new InMemoryFlightSearchJobRepository;
        $dispatcher = new RecordingSearchDispatcher;
        $service = new FlightSearchService($repository, $dispatcher);
        $started = $service->start('search-2', 'trip-1');
        $repository->recordPartial($started->searchId, ['offers' => []]);
        $this->app->instance(FlightSearchJobRepository::class, $repository);
        $this->app->instance(FlightSearchDispatcher::class, $dispatcher);

        $first = $this->getJson('/api/v1/flight-search-jobs/'.$started->searchId)->assertOk();
        $second = $this->getJson('/api/v1/flight-search-jobs/'.$started->searchId)->assertOk();
        self::assertSame($first->json(), $second->json());
        self::assertSame('PARTIAL', $first->json('status'));
        self::assertSame([], $first->json('result.offers'));

        $repository->complete($started->searchId, ['offers' => []]);
        $this->getJson('/api/v1/flight-search-jobs/'.$started->searchId)
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED');
    }

    #[Test]
    public function retry_and_duplicate_delivery_do_not_duplicate_logical_result(): void
    {
        $repository = new InMemoryFlightSearchJobRepository;
        $dispatcher = new RecordingSearchDispatcher;
        $record = (new FlightSearchService($repository, $dispatcher))->start('search-3', 'trip-1');
        $executor = new FlakySearchExecutor;
        $job = new RunFlightSearch($record->searchId);
        $this->app->instance(TripRequestService::class, new StaticTripRequestService);

        try {
            $job->handle($repository, $executor);
            self::fail('İlk deneme başarısız olmalıydı.');
        } catch (RuntimeException) {
            self::assertSame('RUNNING', $repository->find($record->searchId)?->status);
        }

        $job->handle($repository, $executor);
        $job->handle($repository, $executor);

        self::assertSame('COMPLETED', $repository->find($record->searchId)?->status);
        self::assertSame(2, $executor->calls);
        self::assertSame(['offers' => []], $repository->find($record->searchId)?->result);
    }

    #[Test]
    public function expired_job_is_terminal_and_returns_turkish_message(): void
    {
        $repository = new InMemoryFlightSearchJobRepository;
        $record = new FlightSearchJobRecord('expired-1', 'key', 'QUEUED', null, null, null, new DateTimeImmutable('2020-01-01T00:00:00+00:00'));
        $repository->save($record);
        $service = new FlightSearchService($repository, new RecordingSearchDispatcher);

        $polled = $service->poll('expired-1', new DateTimeImmutable('2026-09-27T10:00:00+00:00'));

        self::assertSame('EXPIRED', $polled?->status);
        self::assertSame('Arama işinin geçerlilik süresi doldu.', $polled?->errorMessageTr);
    }
}

final class RecordingSearchDispatcher implements FlightSearchDispatcher
{
    /** @var list<string> */
    public array $searchIds = [];

    public function dispatch(string $searchId): void
    {
        $this->searchIds[] = $searchId;
    }
}

final class FlakySearchExecutor implements FlightSearchExecutor
{
    public int $calls = 0;

    public function isAvailable(): bool
    {
        return true;
    }

    public function execute(FlightSearchCriteria $criteria, callable $publishPartial): FlightSearchOutcome
    {
        $this->calls++;
        if ($this->calls === 1) {
            throw new RuntimeException('provider timeout');
        } $publishPartial([]);

        return FlightSearchOutcome::completed([]);
    }
}
