<?php

namespace App\Modules\Traveler\Domain;

final readonly class Traveler
{
    /** The only category writable while POLICY-MINOR-001 is OPEN; this does not define the OD-020 value set. */
    public const UNGUARDED_AGE_CATEGORY = 'ADULT';

    private const VERIFIED_COLLECTIONS = ['travel_documents', 'held_visas', 'residence_permits'];

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

    /**
     * verification_status is derived, never caller-declared: a caller's FRESH/STALE/EXPIRED claim is stored as UNVERIFIED.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withoutClaimedVerification(array $payload): array
    {
        foreach (self::VERIFIED_COLLECTIONS as $collection) {
            $payload[$collection] = array_map(
                fn (array $entry): array => ['verification_status' => 'UNVERIFIED'] + $entry,
                $payload[$collection],
            );
        }

        return $payload;
    }

    public function requiresProtectedStorage(): bool
    {
        return $this->ageCategory !== self::UNGUARDED_AGE_CATEGORY
            || $this->nationalities !== []
            || $this->travelDocuments !== []
            || $this->heldVisas !== []
            || $this->residencePermits !== [];
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
