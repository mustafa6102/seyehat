<?php

namespace App\Modules\Traveler\Contracts;

use App\Modules\Traveler\Domain\Traveler;

/**
 * The sensitive part of SCH-Traveler v3.3: exactly its fields, so no document number or date is collected before the
 * contract defines one. verification_status is never accepted from a caller (Traveler::withoutClaimedVerification).
 */
final readonly class SensitiveTravelerProfile
{
    public const FIELDS = ['nationalities', 'travel_documents', 'held_visas', 'residence_permits'];

    private const VERIFICATION_STATUSES = ['FRESH', 'STALE', 'EXPIRED', 'UNVERIFIED'];

    private const MAX_TEXT_LENGTH = 255;

    /**
     * @param  list<string>  $nationalities
     * @param  list<array{verification_status: string, document_type: string}>  $travelDocuments
     * @param  list<array{verification_status: string}>  $heldVisas
     * @param  list<array{verification_status: string}>  $residencePermits
     */
    private function __construct(
        public array $nationalities,
        public array $travelDocuments,
        public array $heldVisas,
        public array $residencePermits,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws BasicTravelerRejected
     */
    public static function fromInput(array $input): self
    {
        $known = array_intersect_key($input, array_flip(self::FIELDS));
        if (count($input) !== count(self::FIELDS) || count($known) !== count(self::FIELDS)) {
            throw BasicTravelerRejected::invalidRequest();
        }
        foreach (self::FIELDS as $field) {
            if (! is_array($input[$field]) || ! array_is_list($input[$field])) {
                throw BasicTravelerRejected::invalidRequest();
            }
        }
        foreach ($input['nationalities'] as $nationality) {
            if (! self::validText($nationality)) {
                throw BasicTravelerRejected::invalidRequest();
            }
        }
        self::validateEntries($input['travel_documents'], ['document_type']);
        self::validateEntries($input['held_visas'], []);
        self::validateEntries($input['residence_permits'], []);

        return self::fromStored(Traveler::withoutClaimedVerification($input));
    }

    /**
     * @param  list<mixed>  $entries
     *
     * @throws BasicTravelerRejected
     */
    public static function forField(string $field, array $entries): self
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw BasicTravelerRejected::invalidRequest();
        }

        return self::fromInput([$field => $entries] + array_fill_keys(self::FIELDS, []));
    }

    /** @param array<string, mixed> $fields already validated, decrypted values */
    public static function fromStored(array $fields): self
    {
        return new self($fields['nationalities'], $fields['travel_documents'], $fields['held_visas'], $fields['residence_permits']);
    }

    /** @return array<string, list<mixed>> keyed by field path */
    public function fields(): array
    {
        return [
            'nationalities' => $this->nationalities,
            'travel_documents' => $this->travelDocuments,
            'held_visas' => $this->heldVisas,
            'residence_permits' => $this->residencePermits,
        ];
    }

    /**
     * @param  list<mixed>  $entries
     * @param  list<string>  $requiredText
     */
    private static function validateEntries(array $entries, array $requiredText): void
    {
        $allowed = array_flip([...$requiredText, 'verification_status']);
        foreach ($entries as $entry) {
            if (! is_array($entry) || array_diff_key($entry, $allowed) !== []) {
                throw BasicTravelerRejected::invalidRequest();
            }
            foreach ($requiredText as $key) {
                if (! self::validText($entry[$key] ?? null)) {
                    throw BasicTravelerRejected::invalidRequest();
                }
            }
            if (isset($entry['verification_status']) && ! in_array($entry['verification_status'], self::VERIFICATION_STATUSES, true)) {
                throw BasicTravelerRejected::invalidRequest();
            }
        }
    }

    private static function validText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= self::MAX_TEXT_LENGTH;
    }
}
