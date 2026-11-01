<?php

namespace App\Modules\Traveler\Domain;

use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * The identity ceremony boundary: answers whether this principal proved to be the person of this Traveler. The ceremony and
 * proof type are undecided (ADR-010). Being authenticated or owning the Trip is never such a proof.
 */
interface TravelerIdentityProofs
{
    public function isConfigured(): bool;

    public function verifies(PrincipalRef $principal, string $travelerId, string $tripId, #[\SensitiveParameter] string $proofRef): bool;
}
