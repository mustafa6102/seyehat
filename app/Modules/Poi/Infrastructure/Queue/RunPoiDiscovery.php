<?php

namespace App\Modules\Poi\Infrastructure\Queue;

use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Poi\Application\PoiDiscoveryService;
use App\Modules\Poi\Infrastructure\Overpass\OverpassPoiAdapter;
use App\Modules\Poi\Infrastructure\Persistence\DatabasePoiDiscoveryJobRepository;
use App\Modules\Poi\Infrastructure\Persistence\DatabasePoiRepository;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PoiDiscoveryJobStatus (v3.15, same transitions as FlightSearchJob): criteria, config and capability are checked before
 * RUNNING; any gap FAILS without a provider call (GAP-POI-CRITERIA-001, error.code null).
 */
final class RunPoiDiscovery implements ShouldBeUnique, ShouldQueue
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
        DatabasePoiDiscoveryJobRepository $jobs,
        PoiDiscoveryService $discovery,
        TripRequestService $tripRequests,
        ProviderCapabilityMatrix $capabilities,
        OverpassPoiAdapter $overpass,
        DatabasePoiRepository $pois,
    ): void {
        $job = $jobs->find($this->searchId);
        if ($job === null || $jobs->isSettled($this->searchId)) {
            // Duplicate delivery or DLQ replay of a settled job: idempotent no-op.
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
        $filter = $overpass->discoveryTagFilter();
        $problem = $overpass->configurationProblem() ?? (is_string($filter) ? $filter : null);
        if ($problem !== null) {
            $jobs->fail($this->searchId, null, $problem);

            return;
        }
        // OD-025: FRESH/STALE production capability evidence before any provider call.
        try {
            $supported = $capabilities->productionCapability(OverpassPoiAdapter::PROVIDER_ID, 'poi_discovery')->supported;
        } catch (Throwable) {
            $supported = false;
        }
        if (! $supported) {
            $jobs->fail($this->searchId, null, 'Gezilecek yer araması şu anda kullanılamıyor: gezilecek yer sağlayıcısı yapılandırılmadı.');

            return;
        }

        $jobs->markRunning($this->searchId);
        try {
            $found = $overpass->discover($criteria);
        } catch (ProviderFailure $failure) {
            $jobs->fail($this->searchId, $failure->kind->reasonCode(), $failure->kind->messageTr().' Gezilecek yer araması tamamlanamadı. Lütfen daha sonra yeniden deneyin.');

            return;
        }
        // Canonical POIs must be readable by OP-getPoi / OP-verifyPoi.
        $jobs->complete($this->searchId, array_map(fn (array $poi): array => $pois->save($poi), $found));
    }

    public function failed(?Throwable $exception): void
    {
        // Only identifiers are logged, never exception text.
        Log::warning('queue.dlq.entered', ['job' => 'RunPoiDiscovery', 'search_id' => $this->searchId]);
        app(DatabasePoiDiscoveryJobRepository::class)->fail($this->searchId, null, 'Gezilecek yer araması tamamlanamadı. Lütfen yeniden deneyin.');
    }
}
