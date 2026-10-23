<?php

namespace App\Modules\Poi\Application;

use App\Modules\Poi\Infrastructure\Overpass\OverpassPoiAdapter;
use App\Modules\Poi\Infrastructure\Persistence\DatabasePoiRepository;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * OP-getPoi and OP-verifyPoi (v3.15, OD-025 verification). osm_overpass facts stay UNVERIFIED: verified=true only reports
 * that the provider re-read succeeded. A failed, unavailable or missing read keeps the stored facts unchanged; a deleted
 * element is never treated as closure evidence. No STALE/EXPIRED aging applies (CFG-POI-EVIDENCE-FRESHNESS NOT_REQUIRED).
 */
final readonly class PoiService
{
    public function __construct(
        private DatabasePoiRepository $pois,
        private OverpassPoiAdapter $overpass,
        private ProviderCapabilityMatrix $capabilities,
    ) {}

    /** @return array<string, mixed>|null SCH-Poi */
    public function get(string $poiId): ?array
    {
        return $this->pois->find($poiId);
    }

    /** @return array<string, mixed>|null SCH-PoiVerificationResult, or null when the POI is unknown */
    public function verify(string $poiId, ?DateTimeImmutable $now = null): ?array
    {
        $previous = $this->pois->find($poiId);
        if ($previous === null) {
            return null;
        }
        $capturedAt = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        $unverified = fn (array $reasons): array => ['poi' => $previous, 'captured_at' => $capturedAt, 'verified' => false, 'reason_codes' => $reasons];

        // Config, endpoint policy and FRESH/STALE production capability evidence before any provider call.
        try {
            $supported = $this->overpass->configurationProblem() === null
                && $this->capabilities->productionCapability(OverpassPoiAdapter::PROVIDER_ID, 'poi_verify')->supported;
        } catch (Throwable) {
            $supported = false;
        }
        if (! $supported || ($previous['provider_ref']['provider_id'] ?? null) !== OverpassPoiAdapter::PROVIDER_ID) {
            return $unverified(['RC-EVIDENCE-UNVERIFIED']);
        }
        try {
            $current = $this->overpass->read((string) $previous['provider_ref']['provider_place_id']);
        } catch (ProviderFailure $failure) {
            return $unverified($failure->kind === ProviderFailureKind::TIMEOUT
                ? ['RC-PROVIDER-TIMEOUT', 'RC-EVIDENCE-UNVERIFIED']
                : ['RC-EVIDENCE-UNVERIFIED']);
        }
        if ($current === null) {
            return $unverified(['RC-EVIDENCE-UNVERIFIED']);
        }

        return ['poi' => $this->pois->save($current), 'captured_at' => $capturedAt, 'verified' => true, 'reason_codes' => []];
    }
}
