<?php

namespace App\Modules\Event\Application;

use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Event\Infrastructure\Persistence\DatabaseEventDiscoveryJobRepository;
use App\Modules\Event\Infrastructure\Queue\RunEventDiscovery;
use Illuminate\Support\Str;

/**
 * EVT-001 discovery (v3.22): OP-startEventDiscovery / OP-pollEventDiscovery and server-side criteria derivation.
 * Criteria come only from the persisted TripRequest and config; nothing is taken from the browser.
 */
final readonly class EventDiscoveryService
{
    public function __construct(private DatabaseEventDiscoveryJobRepository $jobs) {}

    /**
     * @return array<string, mixed> the job (existing one for a repeated key)
     *
     * @throws \DomainException 'IDEMPOTENCY_KEY_CONFLICT' when the key belongs to another TripRequest
     */
    public function start(string $idempotencyKey, string $tripRequestId): array
    {
        $existing = $this->jobs->findByIdempotencyKey($idempotencyKey);
        if ($existing === null) {
            $searchId = (string) Str::uuid();
            if ($this->jobs->insertIfAbsent($searchId, $idempotencyKey, $tripRequestId)) {
                RunEventDiscovery::dispatch($searchId)->afterCommit();

                return $this->jobs->find($searchId);
            }
            $existing = $this->jobs->findByIdempotencyKey($idempotencyKey);
        }
        if ($existing['trip_request_id'] !== $tripRequestId) {
            throw new \DomainException('IDEMPOTENCY_KEY_CONFLICT');
        }

        return $existing;
    }

    /** @return array<string, mixed>|null */
    public function poll(string $searchId): ?array
    {
        return $this->jobs->find($searchId);
    }

    /**
     * Derives discovery criteria from the persisted TripRequest. Returns criteria or a concise Turkish reason.
     *
     * @return array<string, mixed>|string
     */
    public function criteria(string $tripRequestId, TripRequestView $tripRequest): array|string
    {
        $destination = $tripRequest->destinationPlace;
        if (($destination['kind'] ?? null) !== 'city') {
            return 'Etkinlik araması için varış noktası bir şehir olarak seçilmiş olmalıdır.';
        }
        $coordinates = $destination['coordinates'] ?? null;
        $latitude = is_array($coordinates) ? ($coordinates['latitude'] ?? null) : null;
        $longitude = is_array($coordinates) ? ($coordinates['longitude'] ?? null) : null;
        if (! is_numeric($latitude) || ! is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return 'Varış şehrinin konumu kayıtlı değil; varış noktasını yeniden seçerek yeni bir talep oluşturun.';
        }

        // Search radius in km (fail-open at 50 km; no env var required).
        $radiusKm = config('services.ticketmaster.search_radius_km', 50);
        $radiusKm = max(1, min(500, (int) $radiusKm));

        // Date window: trip outbound date → return date (inclusive).
        // TravelDate contract: EXACT {type, date} | FLEXIBLE {type, start_date, end_date}.
        $outboundArr = $tripRequest->outboundDate;
        $returnArr = $tripRequest->returnDate;
        $departureDate = is_array($outboundArr) ? ($outboundArr['date'] ?? $outboundArr['start_date'] ?? null) : null;
        $returnDate = is_array($returnArr) ? ($returnArr['date'] ?? $returnArr['end_date'] ?? null) : null;
        // Fallback for legacy date_window model.
        if ($departureDate === null && is_array($tripRequest->dateWindow)) {
            $departureDate = $tripRequest->dateWindow['start_date'] ?? null;
            $returnDate = $returnDate ?? ($tripRequest->dateWindow['end_date'] ?? null);
        }
        $criteria = [
            'trip_request_id' => $tripRequestId,
            'destination_place' => $destination,
            'center_coordinates' => ['latitude' => (float) $latitude, 'longitude' => (float) $longitude],
            'search_radius_km' => $radiusKm,
        ];
        if (is_string($departureDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate) === 1) {
            $criteria['start_date_time'] = $departureDate.'T00:00:00Z';
        }
        if (is_string($returnDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate) === 1) {
            $criteria['end_date_time'] = $returnDate.'T23:59:59Z';
        }

        return $criteria;
    }
}
