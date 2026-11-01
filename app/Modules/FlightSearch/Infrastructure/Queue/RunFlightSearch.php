<?php

namespace App\Modules\FlightSearch\Infrastructure\Queue;

use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\FlightSearch\Application\UnsupportedFlightSearchLocation;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\UnsupportedFlightSearchDate;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use App\Modules\SelfTransfer\Application\SelfTransferDiscoveryOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class RunFlightSearch implements ShouldBeUnique, ShouldQueue
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

    public function handle(FlightSearchJobRepository $jobs, FlightSearchExecutor $executor, ?FlightOfferRepository $offers = null, ?TripRequestService $tripRequests = null, ?SelfTransferDiscoveryOrchestrator $selfTransfers = null): void
    {
        $record = $jobs->find($this->searchId);
        if ($record === null || $record->isSettled()) {
            // Duplicate delivery or DLQ replay of an already settled search: idempotent no-op.
            return;
        }
        // SCH-FlightSearchCriteria v3.5: derived server-side from the job's TripRequest; never guessed.
        if ($record->tripRequestId === null) {
            $jobs->fail($this->searchId, null, 'Bu arama bir seyahat talebine bağlı olmadığı için yürütülemez. Lütfen yeni bir arama başlatın.');

            return;
        }
        $tripRequest = ($tripRequests ?? app(TripRequestService::class))->get($record->tripRequestId);
        if ($tripRequest === null) {
            $jobs->fail($this->searchId, 'RESOURCE_NOT_FOUND', 'Seyahat talebi bulunamadı.');

            return;
        }
        if ($tripRequest->status !== 'SEARCHABLE') {
            $jobs->fail($this->searchId, 'TRIP_REQUEST_INCOMPLETE', 'Seyahat talebinin zorunlu alanları eksik.');

            return;
        }
        try {
            $criteria = FlightSearchCriteria::fromSearchableTripRequest($record->tripRequestId, $tripRequest);
        } catch (UnsupportedFlightSearchDate $unsupportedDate) {
            // GAP-FLT-CRITERIA-001: no canonical reason code for an unrepresentable date.
            $jobs->fail($this->searchId, null, $unsupportedDate->getMessage());

            return;
        } catch (InvalidArgumentException) {
            // Malformed canonical TripRequest data: fail before any provider call; never retried (GAP-FLT-CRITERIA-001).
            $jobs->fail($this->searchId, null, 'Seyahat talebinin bilgileri uçuş arama kriterine dönüştürülemedi. Lütfen yeni bir seyahat talebi oluşturun.');

            return;
        }

        if (! $executor->isAvailable()) {
            // No provider capability/credential: the job ends cleanly (no provider call, no retry churn, no offers).
            $jobs->fail($this->searchId, null, 'Uçuş araması şu anda kullanılamıyor: uçuş sağlayıcısı yapılandırılmadı.');

            return;
        }

        $jobs->markRunning($this->searchId);
        try {
            $outcome = $executor->execute($criteria, fn (array $partial): mixed => $jobs->recordPartial($this->searchId, ['offers' => $this->serialize($partial)]));
        } catch (UnsupportedFlightSearchLocation $unsupported) {
            // GAP-FLT-CRITERIA-001: no canonical reason code exists for an unrepresentable location.
            $jobs->fail($this->searchId, null, $unsupported->getMessage());

            return;
        }
        $result = ['offers' => $this->serialize($outcome->offers)];
        $selfTransfer = ($selfTransfers ?? app(SelfTransferDiscoveryOrchestrator::class))->discover($criteria, $outcome->offers);
        if ($selfTransfer !== null) {
            $result['self_transfer'] = $selfTransfer;
        }
        // Offers shown in results must be retrievable for detail and reprice (OP-getOffer, OP-repriceOffer).
        $offers ??= app(FlightOfferRepository::class);
        foreach ($outcome->offers as $offer) {
            $offers->save($offer);
        }

        if (! $outcome->isDegraded()) {
            $jobs->complete($this->searchId, $result);
        } elseif ($outcome->allProvidersFailed()) {
            $jobs->fail($this->searchId, $outcome->reasonCode(), $outcome->failedMessageTr());
        } else {
            $jobs->recordPartialFailure($this->searchId, $result, $outcome->reasonCode(), $outcome->partialMessageTr());
        }
    }

    public function failed(?Throwable $exception): void
    {
        // The message is now in the DLQ (failed_jobs); only identifiers are logged, never exception text.
        Log::warning('queue.dlq.entered', ['job' => 'RunFlightSearch', 'search_id' => $this->searchId]);
        app(FlightSearchJobRepository::class)->fail($this->searchId, null, 'Uçuş araması tamamlanamadı. Lütfen yeniden deneyin.');
    }

    /** @param list<FlightOffer> $offers @return list<array<string, mixed>> */
    private function serialize(array $offers): array
    {
        return array_map(fn (FlightOffer $offer): array => $offer->toContract(), $offers);
    }
}
