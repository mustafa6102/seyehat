<?php

namespace Tests\Fakes;

use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * Test-only ownership for flows that are not about authorization and use a fake TripRequestService: every TripRequest
 * the bound service knows is owned by the caller, an unknown one is NOT_FOUND. Authorization tests bind the real
 * TripRequestOwnershipGuard instead.
 */
final readonly class ServiceOwnedTripRequests implements TripRequestOwnership
{
    public function __construct(private TripRequestService $tripRequests) {}

    public function check(PrincipalRef $principal, string $tripRequestId): OwnershipDecision
    {
        return $this->tripRequests->get($tripRequestId) === null ? OwnershipDecision::NOT_FOUND : OwnershipDecision::ALLOWED;
    }
}
