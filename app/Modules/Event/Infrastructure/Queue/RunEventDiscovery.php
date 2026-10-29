<?php

namespace App\Modules\Event\Infrastructure\Queue;

use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Event\Application\EventDiscoveryService;
use App\Modules\Event\Infrastructure\Persistence\DatabaseEventDiscoveryJobRepository;
use App\Modules\Event\Infrastructure\Persistence\DatabaseEventRepository;
use App\Modules\Event\Infrastructure\Ticketmaster\TicketmasterEventAdapter;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EVT-001 (v3.22): same lifecycle as RunPoiDiscovery. Criteria, config and capability are checked before RUNNING;
 * any gap FAILS without a provider call. API key is never logged.
 */
final class RunEventDiscovery implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $searchId)
    {
        $this->onQueue('live-ingestion');
    }

    public function uniqueId(): string
    {
        return $this->searchId;
    }

    public function handle(
        DatabaseEventDiscoveryJobRepository $jobs,
        EventDiscoveryService $discovery,
        TripRequestService $tripRequests,
        ProviderCapabilityMatrix $capabilities,
        TicketmasterEventAdapter $adapter,
        DatabaseEventRepository $events,
    ): void {
        $job = $jobs->find($this->searchId);
        if ($job === null || $jobs->isSettled($this->searchId)) {
            return;
        }
        $tripRequest = $tripRequests->get($job['trip_request_id']);
        if ($tripRequest === null) {
            $jobs->fail($this->searchId, 'RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.');

            return;
        }
        if ($tripRequest->status !== 'SEARCHABLE') {
            $jobs->fail($this->searchId, 'TRIP_REQUEST_INCOMPLETE', 'Seyahat talebinin zorunlu alanları eksik.');

            return;
        }
        $criteria = $discovery->criteria($job['trip_request_id'], $tripRequest);
        if (is_string($criteria)) {
            $jobs->fail($this->searchId, null, $criteria);

            return;
        }
        $problem = $adapter->configurationProblem();
        if ($problem !== null) {
            $jobs->fail($this->searchId, null, $problem);

            return;
        }
        // FRESH/STALE production capability evidence before any provider call (OD-EVT-001-TM, ADR-006).
        try {
            $supported = $capabilities->productionCapability(TicketmasterEventAdapter::PROVIDER_ID, 'event_discovery')->supported;
        } catch (Throwable) {
            $supported = false;
        }
        if (! $supported) {
            $jobs->fail($this->searchId, null, 'Etkinlik araması şu anda kullanılamıyor: etkinlik sağlayıcısı yapılandırılmadı.');

            return;
        }

        $jobs->markRunning($this->searchId);
        try {
            $found = $adapter->discover($criteria);
        } catch (ProviderFailure $failure) {
            $jobs->fail($this->searchId, $failure->kind->reasonCode(), $failure->kind->messageTr().' Etkinlik araması tamamlanamadı. Lütfen daha sonra yeniden deneyin.');

            return;
        }
        // Canonical events must be readable by event_id after discovery.
        $jobs->complete($this->searchId, array_map(fn (array $event): array => $events->save($event), $found));
    }

    public function failed(?Throwable $exception): void
    {
        // Only identifiers are logged, never exception text or provider data.
        Log::warning('queue.dlq.entered', ['job' => 'RunEventDiscovery', 'search_id' => $this->searchId]);
        app(DatabaseEventDiscoveryJobRepository::class)->fail($this->searchId, null, 'Etkinlik araması tamamlanamadı. Lütfen yeniden deneyin.');
    }
}
