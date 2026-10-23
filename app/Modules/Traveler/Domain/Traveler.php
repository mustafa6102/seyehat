<?php

namespace App\Modules\Traveler\Domain;

final readonly class Traveler
{
    /**
     * @param  list<string>  $nationalities
     * @param  list<array{document_type: string, verification_status: string}>  $travelDocuments
     * @param  list<array{verification_status: string}>  $heldVisas
     * @param  list<array{verification_status: string}>  $residencePermits
     */
    public function __construct(
        public string $id,
        public string $tripId,
        public string $ageCategory,
        public array $nationalities,
        public array $travelDocuments,
        public array $heldVisas,
        public array $residencePermits,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromContract(array $payload): self
    {
        return new self(
            id: $payload['id'],
            tripId: $payload['trip_id'],
            ageCategory: $payload['age_category'],
            nationalities: $payload['nationalities'],
            travelDocuments: $payload['travel_documents'],
            heldVisas: $payload['held_visas'],
            residencePermits: $payload['residence_permits'],
        );
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->tripId,
            'age_category' => $this->ageCategory,
            'nationalities' => $this->nationalities,
            'travel_documents' => $this->travelDocuments,
            'held_visas' => $this->heldVisas,
            'residence_permits' => $this->residencePermits,
        ];
    }
}
