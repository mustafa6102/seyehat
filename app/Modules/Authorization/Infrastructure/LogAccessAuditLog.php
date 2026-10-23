<?php

namespace App\Modules\Authorization\Infrastructure;

use App\Modules\Authorization\Contracts\AccessDecision;
use App\Modules\Authorization\Contracts\AccessRequest;
use App\Modules\Authorization\Contracts\TripRole;
use App\Modules\Authorization\Domain\AccessAuditLog;
use Illuminate\Support\Facades\Log;

/**
 * Access audit through the redacting application log. Durable audit storage, its retention and the
 * privileged-access log are pending ADR-010 / OD-013. Only identifiers are written, never PII values.
 */
final class LogAccessAuditLog implements AccessAuditLog
{
    public function record(AccessRequest $request, AccessDecision $decision): void
    {
        Log::log($decision->allowed ? 'info' : 'warning', 'access.decision', [
            'subject_id' => $request->subjectId,
            'roles' => array_map(fn (TripRole $role): string => $role->value, $request->rolesInTrip),
            'resource' => $request->resource,
            'action' => $request->action,
            'trip_id' => $request->tripId,
            'traveler_id' => $request->travelerId,
            'decision' => $decision->allowed ? 'ALLOW' : 'DENY',
            'policy' => $decision->policy,
            'reason' => $decision->reason,
            'decision_ref' => $decision->decisionRef,
            'matrix_version' => 'permissions.yaml@3.2.2',
        ]);
    }
}
