<?php

namespace App\Modules\Api\Contracts;

use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * RULE-PLT-AUTH-001-04..06: authorization of an authenticated principal against a TripRequest owner. Authentication (401)
 * happens before this; the result here is only ALLOWED, DENIED (403 RC-AUTHZ-DENIED) or NOT_FOUND (404). Trip and
 * PlanRevision authorization derive from the originating TripRequest through this check (OD-021).
 */
interface TripRequestOwnership
{
    public function check(PrincipalRef $principal, string $tripRequestId): OwnershipDecision;
}
