<?php

namespace App\Modules\Traveler\Domain;

use App\Modules\Traveler\Contracts\BasicTraveler;
use App\Modules\Traveler\Contracts\BasicTravelerRejected;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;

/** Atomic Basic Traveler storage, separate from TravelerRepository; implementations keep its plaintext refusal. */
interface TravelerEnrollmentStore
{
    /**
     * @param  int|null  $adultCapacity  null = unknown, which never admits a new row but still replays
     * @param  string  $createdAt  DATETIME(6) in UTC
     *
     * @throws BasicTravelerRejected
     * @throws TravelerStorageClosed
     */
    public function enroll(Traveler $traveler, string $idempotencyKey, string $fingerprint, ?int $adultCapacity, string $createdAt): BasicTraveler;

    public function hasUnverifiedRows(string $tripId): bool;

    /** @return list<BasicTraveler> oldest first */
    public function listForTrip(string $tripId): array;

    public function findInTrip(string $tripId, string $travelerId): ?BasicTraveler;
}
