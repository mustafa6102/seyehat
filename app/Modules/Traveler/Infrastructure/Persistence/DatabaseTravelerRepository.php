<?php

namespace App\Modules\Traveler\Infrastructure\Persistence;

use App\Modules\Traveler\Domain\Traveler;
use App\Modules\Traveler\Domain\TravelerRepository;
use Illuminate\Support\Facades\DB;

final class DatabaseTravelerRepository implements TravelerRepository
{
    public function save(Traveler $traveler): Traveler
    {
        DB::table('travelers')->updateOrInsert(
            ['id' => $traveler->id],
            [
                'trip_id' => $traveler->tripId,
                'age_category' => $traveler->ageCategory,
                'nationalities' => json_encode($traveler->nationalities, JSON_THROW_ON_ERROR),
                'travel_documents' => json_encode($traveler->travelDocuments, JSON_THROW_ON_ERROR),
                'held_visas' => json_encode($traveler->heldVisas, JSON_THROW_ON_ERROR),
                'residence_permits' => json_encode($traveler->residencePermits, JSON_THROW_ON_ERROR),
            ],
        );

        return $traveler;
    }

    public function find(string $id): ?Traveler
    {
        $row = DB::table('travelers')->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return new Traveler(
            id: $row->id,
            tripId: $row->trip_id,
            ageCategory: $row->age_category,
            nationalities: $this->decode($row->nationalities),
            travelDocuments: $this->decode($row->travel_documents),
            heldVisas: $this->decode($row->held_visas),
            residencePermits: $this->decode($row->residence_permits),
        );
    }

    /** @return array<int, mixed> */
    private function decode(string $json): array
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
