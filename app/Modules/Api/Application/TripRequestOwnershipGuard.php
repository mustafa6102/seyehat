<?php

namespace App\Modules\Api\Application;

use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Domain\TripRequest\TripRequestRepository;
use App\Modules\Authorization\Contracts\PrincipalRef;

/** Exact (issuer, subject) comparison with the stored TripRequest owner; an ownerless legacy record is always DENIED. */
final readonly class TripRequestOwnershipGuard implements TripRequestOwnership
{
    public function __construct(private TripRequestRepository $tripRequests) {}

    public function check(PrincipalRef $principal, string $tripRequestId): OwnershipDecision
    {
        $tripRequest = $this->tripRequests->find($tripRequestId);
        if ($tripRequest === null) {
            return OwnershipDecision::NOT_FOUND;
        }
        $owner = $tripRequest->owner();

        return $owner !== null && $owner->equals($principal) ? OwnershipDecision::ALLOWED : OwnershipDecision::DENIED;
    }
}
