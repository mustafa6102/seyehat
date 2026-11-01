<?php

namespace App\Modules\Traveler\Contracts;

use App\Modules\Authorization\Contracts\AccessDenied;
use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * Encrypted passport, nationality, visa and residence permit data of one ADULT Basic Traveler. No route reaches this yet.
 * Order: activation, TripRequest and Trip scope, Trip role, Traveler in Trip, policy, then key use. Writes only add: removing
 * or correcting an entry is a deletion, which stays closed until POLICY-SUBJECT-DELETE-001 and OD-013 are decided.
 */
interface SensitiveTravelerProfiles
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws TravelerStorageClosed
     * @throws BasicTravelerRejected
     * @throws AccessDenied
     */
    public function put(PrincipalRef $principal, string $tripRequestId, string $travelerId, array $input): void;

    /**
     * @param  string  $field  one of SensitiveTravelerProfile::FIELDS
     * @param  list<mixed>  $entries
     *
     * @throws TravelerStorageClosed
     * @throws BasicTravelerRejected
     * @throws AccessDenied
     */
    public function addEntries(PrincipalRef $principal, string $tripRequestId, string $travelerId, string $field, array $entries): void;

    /**
     * @return SensitiveTravelerProfile|null null when nothing was stored for this Traveler
     *
     * @throws TravelerDataUnavailable
     * @throws BasicTravelerRejected
     * @throws AccessDenied
     */
    public function read(PrincipalRef $principal, string $tripRequestId, string $travelerId): ?SensitiveTravelerProfile;
}
