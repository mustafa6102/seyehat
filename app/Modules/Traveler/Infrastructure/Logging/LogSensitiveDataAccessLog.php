<?php

namespace App\Modules\Traveler\Infrastructure\Logging;

use App\Modules\Authorization\Contracts\AuditIdentity;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Traveler\Domain\SensitiveDataAccessLog;
use App\Modules\Traveler\Domain\Traveler;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

/**
 * Access record through the redacting application log: subject/action/resource/timestamp, never values.
 * The subject is the pseudonym of the principal the auth middleware attached to the current request, and null outside
 * an authenticated request. Durable audit storage and per-subject key encryption of audit records are pending
 * ADR-010 / OD-013.
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
            'subject_id' => $this->subjectId(),
            'action' => $action,
            'resource' => 'Traveler.sensitive_pii',
            'resources' => $resources,
            'traveler_id' => $traveler->id,
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);
    }

    public function recordFields(string $action, PrincipalRef $principal, string $tripRequestId, string $travelerId, array $fields, string $operationId): void
    {
        $identity = app(AuditIdentity::class);

        Log::channel('pii_access')->info('pii.access', [
            'subject_id' => $identity->subjectId($principal),
            'action' => $action,
            'resource' => 'Traveler.sensitive_pii',
            'resources' => $fields,
            'traveler_id' => $travelerId,
            'trip_correlation_id' => $identity->tripCorrelationId($tripRequestId),
            'operation_id' => $operationId,
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);
    }

    private function subjectId(): ?string
    {
        $principal = app()->bound('request') ? app('request')->attributes->get(PrincipalRef::class) : null;

        return $principal instanceof PrincipalRef ? app(AuditIdentity::class)->subjectId($principal) : null;
    }
}
