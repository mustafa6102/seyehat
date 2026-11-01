<?php

namespace App\Modules\Traveler\Domain;

use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * Which Travelers of a Trip represent a principal. A binding exists only after TravelerIdentityProofs accepted the proof; a
 * client value or a Trip role never creates one. One Traveler has one binding for life, and one principal one per Trip.
 */
interface TravelerSubjectBindings
{
    /** @return list<string> Travelers actively bound to exactly this (issuer, subject) */
    public function ownTravelerIds(PrincipalRef $principal, string $tripId): array;

    /** @return bool false when the Traveler or this principal is already bound in the Trip, even if since revoked */
    public function bind(string $travelerId, string $tripId, PrincipalRef $principal, string $proofRef, string $verifiedAt): bool;

    /** @return bool false unless this exact principal holds an active binding on the Traveler */
    public function revoke(string $travelerId, string $tripId, PrincipalRef $principal, string $revokedAt): bool;
}
