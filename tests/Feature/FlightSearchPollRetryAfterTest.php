<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\FlightSearch\Domain\FlightSearchJobRecord;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryFlightSearchJobRepository;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\Fakes\ServiceOwnedTripRequests;
use Tests\Fakes\StaticTripRequestService;
use Tests\TestCase;

/**
 * OP-pollFlightSearch (PLT-REL-001 / ADR-011): Retry-After is polling guidance for a job that can still change; its
 * value comes only from deployment configuration and is never invented.
 */
final class FlightSearchPollRetryAfterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Flight flow, not authorization: the caller owns every TripRequest the bound service knows (v3.16 bearerAuth).
        $this->app->bind(TripRequestOwnership::class, ServiceOwnedTripRequests::class);
        $this->withToken(FixedBearerAuthenticator::TOKEN);
        $this->app->instance(TripRequestService::class, new StaticTripRequestService);

        $jobs = new InMemoryFlightSearchJobRepository;
        $this->app->instance(FlightSearchJobRepository::class, $jobs);
        $expires = new DateTimeImmutable('+1 hour');
        foreach ([
            ['job-queued', 'QUEUED', null, null],
            ['job-running', 'RUNNING', null, null],
            ['job-partial', 'PARTIAL', ['offers' => []], null],
            ['job-partial-final', 'PARTIAL', ['offers' => []], 'Sağlayıcıya şu anda ulaşılamıyor.'],
            ['job-completed', 'COMPLETED', ['offers' => []], null],
            ['job-failed', 'FAILED', null, 'Uçuş araması tamamlanamadı.'],
        ] as [$id, $status, $result, $message]) {
            $jobs->save(new FlightSearchJobRecord($id, 'key-'.$id, $status, $result, null, $message, $expires, 'trip-1'));
        }
    }

    #[Test]
    public function a_changing_job_carries_the_configured_polling_guidance_in_whole_seconds(): void
    {
        config(['flight_search.poll_interval_ms' => 2500]);

        foreach (['job-queued', 'job-running', 'job-partial'] as $id) {
            $this->getJson('/api/v1/flight-search-jobs/'.$id)->assertOk()->assertHeader('Retry-After', '3');
        }
        config(['flight_search.poll_interval_ms' => 400]);
        $this->getJson('/api/v1/flight-search-jobs/job-queued')->assertHeader('Retry-After', '1');
    }

    #[Test]
    public function settled_jobs_and_unconfigured_deployments_get_no_retry_after(): void
    {
        config(['flight_search.poll_interval_ms' => 3000]);
        foreach (['job-partial-final', 'job-completed', 'job-failed'] as $id) {
            $this->getJson('/api/v1/flight-search-jobs/'.$id)->assertOk()->assertHeaderMissing('Retry-After');
        }

        config(['flight_search.poll_interval_ms' => null]);
        $this->getJson('/api/v1/flight-search-jobs/job-queued')->assertOk()->assertHeaderMissing('Retry-After');
        // The body is the unchanged contract representation.
        $this->getJson('/api/v1/flight-search-jobs/job-queued')->assertJsonPath('status', 'QUEUED')->assertJsonPath('search_id', 'job-queued');
    }
}
