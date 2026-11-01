<?php

namespace App\Modules\Traveler\Contracts;

/** The whole Basic Traveler projection: no sensitive column is ever selected into it. */
final readonly class BasicTraveler
{
    public function __construct(
        public string $id,
        public string $tripId,
        public string $ageCategory,
        public string $createdAt,
    ) {}

    /** @return array{id: string, trip_id: string, age_category: string, created_at: string} */
    public function toContract(): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->tripId,
            'age_category' => $this->ageCategory,
            'created_at' => $this->createdAt,
        ];
    }
}
