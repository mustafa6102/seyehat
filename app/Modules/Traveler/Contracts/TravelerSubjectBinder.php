<?php

namespace App\Modules\Traveler\Contracts;

use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * Links an authenticated person to their own Traveler record. The claimant is always the bound principal, so an OWNER cannot
 * bind someone else, and no route reaches this yet.
 */
interface TravelerSubjectBinder
{
    /**
     * @throws TravelerStorageClosed
     * @throws TravelerBindingRejected
     */
    public function bind(PrincipalRef $claimant, string $tripRequestId, string $travelerId, #[\SensitiveParameter] string $proofRef): void;

    /**
     * Only the bound person can revoke; the Traveler then stays unbound.
     *
     * @throws TravelerStorageClosed
     * @throws TravelerBindingRejected
     */
    public function revoke(PrincipalRef $principal, string $tripRequestId, string $travelerId): void;
}
