<?php

namespace App\Modules\Poi\Application;

use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Poi\Infrastructure\Persistence\DatabasePoiDiscoveryJobRepository;
use App\Modules\Poi\Infrastructure\Queue\RunPoiDiscovery;
use Illuminate\Support\Str;

/**
 * POI-001 discovery (v3.15): OP-startPoiDiscovery / OP-pollPoiDiscovery and the server-side SCH-PoiDiscoveryCriteria
 * derivation. Criteria come only from the persisted TripRequest and the v3.15 config; nothing is taken from the browser.
 */
final readonly class PoiDiscoveryService
{
    public function __construct(private DatabasePoiDiscoveryJobRepository $jobs) {}

    /**
     * @return array<string, mixed> the job (existing one for a repeated key)
     *
     * @throws \DomainException 'IDEMPOTENCY_KEY_CONFLICT' when the key belongs to another TripRequest's discovery
     */
    public function start(string $idempotencyKey, string $tripRequestId): array
    {
        $existing = $this->jobs->findByIdempotencyKey($idempotencyKey);
        if ($existing === null) {
            $searchId = (string) Str::uuid();
            if ($this->jobs->insertIfAbsent($searchId, $idempotencyKey, $tripRequestId)) {
                RunPoiDiscovery::dispatch($searchId)->afterCommit();

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
     * SCH-PoiDiscoveryCriteria x-mapping.derivable_when. Returns the criteria, or a concise Turkish reason when they cannot be
     * derived (GAP-POI-CRITERIA-001: the job then FAILS with error.code null and no provider call).
     *
     * @return array<string, mixed>|string
     */
    public function criteria(string $tripRequestId, TripRequestView $tripRequest): array|string
    {
        $destination = $tripRequest->destinationPlace;
        if (($destination['kind'] ?? null) !== 'city') {
            return 'Gezilecek yer araması için varış noktası bir şehir olarak seçilmiş olmalıdır.';
        }
        // Only persisted, server-verified city coordinates; an airport position is never used and nothing is inferred.
        $coordinates = $destination['coordinates'] ?? null;
        $latitude = is_array($coordinates) ? ($coordinates['latitude'] ?? null) : null;
        $longitude = is_array($coordinates) ? ($coordinates['longitude'] ?? null) : null;
        if (! is_numeric($latitude) || ! is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return 'Varış şehrinin konumu kayıtlı değil; varış noktasını yeniden seçerek yeni bir talep oluşturun.';
        }
        // CFG-POI-SEARCH-RADIUS (500-20000 m) and CFG-POI-MAX-RESULTS (1-200): no other value is substituted.
        $radius = config('poi.search_radius_m');
        $maxResults = config('poi.max_results');
        if (! is_int($radius) || $radius < 500 || $radius > 20000 || ! is_int($maxResults) || $maxResults < 1 || $maxResults > 200) {
            return 'Gezilecek yer araması şu anda kullanılamıyor: arama ayarları yapılandırılmadı.';
        }

        return [
            'trip_request_id' => $tripRequestId,
            'destination_place' => $destination,
            'center_coordinates' => ['latitude' => (float) $latitude, 'longitude' => (float) $longitude],
            'search_radius' => $radius,
            'max_results' => $maxResults,
        ];
    }
}
