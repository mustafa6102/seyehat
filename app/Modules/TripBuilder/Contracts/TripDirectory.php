<?php

namespace App\Modules\TripBuilder\Contracts;

/** Read-only Trip lookup for other modules (OD-021): a Trip exists only as a stored row bound to one TripRequest. */
interface TripDirectory
{
    public function tripRequestIdOf(string $tripId): ?string;

    public function tripIdOf(string $tripRequestId): ?string;

    /** Takes the Trip row lock (FOR UPDATE) inside the caller's transaction; false when no such Trip exists to lock. */
    public function lock(string $tripId): bool;
}
