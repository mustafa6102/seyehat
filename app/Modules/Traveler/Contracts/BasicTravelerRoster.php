<?php

namespace App\Modules\Traveler\Contracts;

final readonly class BasicTravelerRoster
{
    /**
     * @param  int|null  $adultCapacity  null when the TripRequest has no passenger_composition
     * @param  list<BasicTraveler>  $travelers
     * @param  bool  $hasUnverifiedRows  the Trip holds rows whose membership cannot be proven, so no adult can be added
     */
    public function __construct(
        public bool $tripExists,
        public ?int $adultCapacity,
        public array $travelers,
        public bool $hasUnverifiedRows,
    ) {}

    public function canAddAdult(): bool
    {
        return $this->tripExists && ! $this->hasUnverifiedRows
            && $this->adultCapacity !== null && count($this->travelers) < $this->adultCapacity;
    }
}
