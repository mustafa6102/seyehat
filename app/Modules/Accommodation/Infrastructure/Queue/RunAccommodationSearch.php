<?php

namespace App\Modules\Accommodation\Infrastructure\Queue;

use App\Modules\Accommodation\Application\AccommodationOfferService;
use App\Modules\Accommodation\Application\AccommodationSearchService;
use App\Modules\Accommodation\Infrastructure\Duffel\DuffelStaysAdapter;
use App\Modules\Accommodation\Infrastructure\Persistence\DatabaseAccommodationSearchJobRepository;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AccommodationSearchJobStatus (v3.14, same transitions as FlightSearchJob): criteria and capability are checked before
 * RUNNING; unsupported criteria or missing capability evidence FAIL without any provider call (GAP-ACC-CRITERIA-001).
 */
final class RunAccommodationSearch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $searchId) {}

    public function uniqueId(): string
    {
        return $this->searchId;
    }

    public function handle(
        DatabaseAccommodationSearchJobRepository $jobs,
        AccommodationSearchService $searches,
        TripRequestService $tripRequests,
        ProviderCapabilityMatrix $capabilities,
        DuffelStaysAdapter $stays,
        AccommodationOfferService $offerService,
    ): void {
        $job = $jobs->find($this->searchId);
        if ($job === null || $jobs->isSettled($this->searchId)) {
            // Duplicate delivery or DLQ replay of a settled search: idempotent no-op.
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
        $criteria = $searches->criteria($job['trip_request_id'], $tripRequest);
        if (is_string($criteria)) {
            $jobs->fail($this->searchId, null, $criteria);

            return;
        }
        // OD-023: credential plus FRESH/STALE production capability evidence before any provider call.
        try {
            $supported = DuffelStaysAdapter::isConfigured()
                && $capabilities->productionCapability(DuffelStaysAdapter::PROVIDER_ID, 'accommodation_search')->supported;
        } catch (Throwable) {
            $supported = false;
        }
        if (! $supported) {
            $jobs->fail($this->searchId, null, 'Konaklama araması şu anda kullanılamıyor: konaklama sağlayıcısı yapılandırılmadı.');

            return;
        }
        // CFG-ACCOMMODATION-SEARCH-MAX-PROPERTIES is OPEN: without a configured value nothing is substituted.
        if (DuffelStaysAdapter::maxProperties() === null) {
            $jobs->fail($this->searchId, null, 'Konaklama araması şu anda kullanılamıyor: arama sonucu sınırı yapılandırılmadı.');

            return;
        }

        $jobs->markRunning($this->searchId);
        try {
            $offers = $stays->search($criteria);
        } catch (ProviderFailure $failure) {
            $jobs->fail($this->searchId, $failure->kind->reasonCode(), $failure->kind->messageTr().' Konaklama araması tamamlanamadı. Lütfen daha sonra yeniden deneyin.');

            return;
        }
        // Discovered offers (UNVERIFIED, TRN-ACCOFFER-04) must be readable for OP-getAccommodationOffer / revalidation.
        $offerService->remember($offers);
        $jobs->complete($this->searchId, $offers);
    }

    public function failed(?Throwable $exception): void
    {
        // Only identifiers are logged, never exception text.
        Log::warning('queue.dlq.entered', ['job' => 'RunAccommodationSearch', 'search_id' => $this->searchId]);
        app(DatabaseAccommodationSearchJobRepository::class)->fail($this->searchId, null, 'Konaklama araması tamamlanamadı. Lütfen yeniden deneyin.');
    }
}
