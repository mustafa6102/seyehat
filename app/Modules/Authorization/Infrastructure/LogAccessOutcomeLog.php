<?php

namespace App\Modules\Authorization\Infrastructure;

use App\Modules\Authorization\Contracts\AccessOutcomeLog;
use App\Modules\Authorization\Contracts\AuditIdentity;
use Illuminate\Support\Facades\Log;

/** Same channel as access.decision. Written after commit: a failed write is healed by the idempotent retry's REPLAYED record. */
final readonly class LogAccessOutcomeLog implements AccessOutcomeLog
{
    public function __construct(private AuditIdentity $identity) {}

    public function recordTravelerCreate(string $operationId, string $subjectId, string $tripRequestId, string $travelerId, string $outcome): void
    {
        Log::channel('access_audit')->info('access.outcome', [
            'operation_id' => $operationId,
            'subject_id' => $subjectId,
            'resource' => 'Traveler.basic',
            'action' => 'create',
            'trip_correlation_id' => $this->identity->tripCorrelationId($tripRequestId),
            'traveler_id' => $travelerId,
            'outcome' => $outcome,
        ]);
    }

    public function recordSubjectRequest(string $requestId, string $subjectId, string $tripRequestId, string $travelerId, string $type, string $status): void
    {
        Log::channel('access_audit')->info('access.subject_request', [
            'request_id' => $requestId,
            'subject_id' => $subjectId,
            'resource' => 'Traveler.basic',
            'request_type' => $type,
            'status' => $status,
            'trip_correlation_id' => $this->identity->tripCorrelationId($tripRequestId),
            'traveler_id' => $travelerId,
        ]);
    }
}
