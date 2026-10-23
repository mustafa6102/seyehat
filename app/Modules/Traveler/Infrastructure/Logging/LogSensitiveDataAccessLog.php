<?php

namespace App\Modules\Traveler\Infrastructure\Logging;

use App\Modules\Traveler\Domain\SensitiveDataAccessLog;
use App\Modules\Traveler\Domain\Traveler;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

/**
 * Access record through the redacting application log: subject/action/resource/timestamp, never values.
 * The authenticated subject is unavailable until AuthN exists (ADR-010); durable audit storage and
 * per-subject key encryption of audit records are pending ADR-010 / OD-013.
 */
final class LogSensitiveDataAccessLog implements SensitiveDataAccessLog
{
    public function record(string $action, Traveler $traveler): void
    {
        $resources = array_keys(array_filter([
            'TravelerDocument' => $traveler->travelDocuments !== [],
            'Visa' => $traveler->heldVisas !== [],
            'ResidencePermit' => $traveler->residencePermits !== [],
        ]));

        if ($resources === []) {
            return;
        }

        Log::channel('pii_access')->info('pii.access', [
            'subject_id' => null,
            'action' => $action,
            'resource' => 'Traveler.sensitive_pii',
            'resources' => $resources,
            'traveler_id' => $traveler->id,
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);
    }
}
