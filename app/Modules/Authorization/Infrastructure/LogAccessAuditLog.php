<?php

namespace App\Modules\Authorization\Infrastructure;

use App\Modules\Authorization\Contracts\AccessDecision;
use App\Modules\Authorization\Contracts\AccessRequest;
use App\Modules\Authorization\Contracts\AuditIdentity;
use App\Modules\Authorization\Contracts\TripRole;
use App\Modules\Authorization\Domain\AccessAuditLog;
use Illuminate\Support\Facades\Log;

/**
 * Access audit through its own redacting channel, so LOG_LEVEL cannot drop a decision. The append-only audit store, its
 * retention and the privileged-access log are pending ADR-010 / OD-013. Only identifiers are written, never PII values:
 * trip_id stays redacted by the logging policy, so records of one Trip are joined on trip_correlation_id, and the channel
 * writes traveler_id as its AuditIdentity pseudonym.
 */
final class LogAccessAuditLog implements AccessAuditLog
{
    public function record(AccessRequest $request, AccessDecision $decision): void
    {
        Log::channel('access_audit')->log($decision->allowed ? 'info' : 'warning', 'access.decision', [
            'subject_id' => $request->subjectId,
            'roles' => array_map(fn (TripRole $role): string => $role->value, $request->rolesInTrip),
            'resource' => $request->resource,
            'action' => $request->action,
            'trip_id' => $request->tripId,
            'trip_correlation_id' => $request->tripId === null || $request->tripId === ''
                ? null
                : app(AuditIdentity::class)->tripCorrelationId($request->tripId),
            'traveler_id' => $request->travelerId,
            'operation_id' => $request->operationId,
            'decision' => $decision->allowed ? 'ALLOW' : 'DENY',
            'policy' => $decision->policy,
            'reason' => $decision->reason,
            'decision_ref' => $decision->decisionRef,
            'matrix_version' => 'permissions.yaml@3.2.2',
        ]);
    }
}
