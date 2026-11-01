<?php

namespace App\Modules\Authorization\Application;

use App\Modules\Authorization\Contracts\AccessControl;
use App\Modules\Authorization\Contracts\AccessDecision;
use App\Modules\Authorization\Contracts\AccessDenied;
use App\Modules\Authorization\Contracts\AccessRequest;
use App\Modules\Authorization\Contracts\TripRole;
use App\Modules\Authorization\Domain\AccessAuditLog;
use App\Modules\Authorization\Domain\PermissionMatrix;

/**
 * Least-privilege AuthZ over the permissions.yaml matrix (RULE-PLT-AUTH-001-01/02, FR-PLT-AUTH-001-01).
 * Policies whose conditions depend on OPEN decisions (OD-013) fail closed.
 */
final readonly class MatrixAccessControl implements AccessControl
{
    /** Account rows conflict between the matrix and authorization_tests in permissions.yaml. */
    private const CONFLICTING_POLICIES = ['POLICY-ACCOUNT-SELF-001', 'POLICY-KVKK-001'];

    public function __construct(private AccessAuditLog $audit) {}

    public function authorize(AccessRequest $request): AccessDecision
    {
        $decision = $this->decide($request);
        $this->audit->record($request, $decision);

        return $decision;
    }

    public function ensure(AccessRequest $request): void
    {
        $decision = $this->authorize($request);
        if (! $decision->allowed) {
            throw new AccessDenied($decision);
        }
    }

    private function decide(AccessRequest $request): AccessDecision
    {
        if ($request->tripId === null || $request->tripId === '') {
            return new AccessDecision(false, 'DENY', AccessDecision::SCOPE_MISSING);
        }

        $denial = null;
        foreach ($request->rolesInTrip as $role) {
            $cell = PermissionMatrix::decision($request->resource, $request->action, $role->value);
            $decision = $cell === null
                ? new AccessDecision(false, 'DENY', AccessDecision::UNKNOWN_PERMISSION)
                : $this->evaluate($cell, $role, $request);

            if ($decision->allowed) {
                return $decision;
            }
            // Report the most informative denial: an OPEN decision outranks a plain matrix DENY.
            if ($denial === null || $denial->reason === AccessDecision::MATRIX_DENY) {
                $denial = $decision;
            }
        }

        return $denial ?? new AccessDecision(false, 'DENY', AccessDecision::MATRIX_DENY);
    }

    private function evaluate(string $cell, TripRole $role, AccessRequest $request): AccessDecision
    {
        $allow = fn (): AccessDecision => new AccessDecision(true, $cell, $cell === 'ALLOW' ? AccessDecision::MATRIX_ALLOW : AccessDecision::POLICY_SATISFIED);
        $deny = fn (string $reason, ?string $ref = null): AccessDecision => new AccessDecision(false, $cell, $reason, $ref);

        if (in_array($cell, self::CONFLICTING_POLICIES, true)) {
            return $deny(AccessDecision::SSOT_CONFLICT);
        }

        return match ($cell) {
            'ALLOW' => $allow(),
            'DENY' => $deny(AccessDecision::MATRIX_DENY),
            // Role is held in the requested trip, which is the membership condition.
            'POLICY-TRIP-001' => $allow(),
            'POLICY-SYSTEM-001' => ($request->systemWorkflow ?? '') !== '' ? $allow() : $deny(AccessDecision::SYSTEM_WORKFLOW_REQUIRED),
            'POLICY-TRAVELER-001' => $this->ownerCreatesTraveler($request, $role)
                ? $allow()
                : $this->travelerScope($request, $role === TripRole::TRAVELER, $allow, $deny),
            'POLICY-PII-VIEW-001', 'POLICY-SUBJECT-DELETE-001' => $request->travelerIsMinor
                ? $deny(AccessDecision::DECISION_OPEN, 'OD-013')
                : $this->travelerScope($request, true, $allow, $deny, openForOthers: true),
            'POLICY-MINOR-001' => $deny(AccessDecision::DECISION_OPEN, 'OD-013'),
            default => $deny(AccessDecision::UNKNOWN_PERMISSION),
        };
    }

    /** A record that does not exist has no traveler scope: the OWNER's role in the trip is the whole condition (D5). */
    private function ownerCreatesTraveler(AccessRequest $request, TripRole $role): bool
    {
        return $request->creatingTraveler && $role === TripRole::OWNER && $request->travelerId === null
            && $request->resource === 'Traveler.basic' && $request->action === 'create';
    }

    /**
     * @param  callable(): AccessDecision  $allow
     * @param  callable(string, ?string=): AccessDecision  $deny
     */
    private function travelerScope(AccessRequest $request, bool $selfOnly, callable $allow, callable $deny, bool $openForOthers = false): AccessDecision
    {
        $travelerId = $request->travelerId;
        if ($travelerId === null || ! in_array($travelerId, $request->tripTravelerIds, true)) {
            return $deny($travelerId === null ? AccessDecision::SCOPE_MISSING : AccessDecision::TRAVELER_NOT_IN_TRIP);
        }
        if (in_array($travelerId, $request->ownTravelerIds, true)) {
            return $allow();
        }

        // Another adult's sensitive data needs a legal basis that OD-013 has not decided yet.
        return $selfOnly ? $deny($openForOthers ? AccessDecision::DECISION_OPEN : AccessDecision::NOT_SELF, $openForOthers ? 'OD-013' : null) : $allow();
    }
}
