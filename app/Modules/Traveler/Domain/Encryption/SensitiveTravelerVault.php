<?php

namespace App\Modules\Traveler\Domain\Encryption;

use App\Modules\Traveler\Contracts\SensitiveTravelerProfile;
use App\Modules\Traveler\Contracts\TravelerDataUnavailable;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;

/**
 * Envelope-encrypted storage, the only place sensitive Traveler values may be stored. It performs no authorization. Deletion
 * and crypto-shredding are absent on purpose: their triggers, order and backup scope are OD-013 decisions.
 */
interface SensitiveTravelerVault
{
    /**
     * Adds the entries not yet stored; an existing entry is never removed, so an empty list changes nothing.
     *
     * @throws TravelerStorageClosed
     */
    public function store(string $travelerId, SensitiveTravelerProfile $profile): void;

    /**
     * @return SensitiveTravelerProfile|null null when no data key exists for this Traveler
     *
     * @throws TravelerDataUnavailable
     */
    public function load(string $travelerId): ?SensitiveTravelerProfile;

    /**
     * Re-wraps one data key under the current KEK; field ciphertext is untouched.
     *
     * @return bool false when there is no key or it is already current
     *
     * @throws TravelerStorageClosed
     * @throws TravelerDataUnavailable
     */
    public function rewrap(string $travelerId): bool;

    /**
     * Re-wraps up to $limit keys that are not under the current KEK. A key that fails stays as it is and is counted.
     *
     * @return array{rewrapped: int, failed: int}
     *
     * @throws TravelerStorageClosed
     */
    public function rewrapStale(int $limit): array;
}
