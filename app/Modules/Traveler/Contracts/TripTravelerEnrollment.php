<?php

namespace App\Modules\Traveler\Contracts;

use App\Modules\Authorization\Contracts\AccessDenied;
use App\Modules\Authorization\Contracts\PrincipalRef;

/**
 * TRV-001 Basic Traveler (D1-D6): server-generated, insert-only, ADULT without sensitive fields, always in the scope of an
 * owned TripRequest. Check order: TripRequest 404, owner 403, request 400, Trip 409, replay, capacity 409. Callers expose
 * this only behind BasicTravelerActivation.
 */
interface TripTravelerEnrollment
{
    /**
     * @param  array<string, mixed>  $profile  exactly {age_category: "ADULT"}
     *
     * @throws BasicTravelerRejected
     * @throws AccessDenied
     * @throws TravelerStorageClosed
     */
    public function enroll(PrincipalRef $principal, string $tripRequestId, mixed $idempotencyKey, array $profile): BasicTraveler;

    /**
     * @throws BasicTravelerRejected
     * @throws AccessDenied
     */
    public function roster(PrincipalRef $principal, string $tripRequestId): BasicTravelerRoster;

    /**
     * @throws BasicTravelerRejected
     * @throws AccessDenied
     */
    public function find(PrincipalRef $principal, string $tripRequestId, string $travelerId): BasicTraveler;
}
