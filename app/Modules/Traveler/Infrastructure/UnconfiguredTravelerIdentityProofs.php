<?php

namespace App\Modules\Traveler\Infrastructure;

use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Traveler\Domain\TravelerIdentityProofs;

/** The default until a ceremony is approved: no proof is ever accepted, so no binding can be created. */
final class UnconfiguredTravelerIdentityProofs implements TravelerIdentityProofs
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function verifies(PrincipalRef $principal, string $travelerId, string $tripId, #[\SensitiveParameter] string $proofRef): bool
    {
        return false;
    }
}
