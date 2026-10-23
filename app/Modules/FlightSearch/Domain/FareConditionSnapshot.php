<?php

namespace App\Modules\FlightSearch\Domain;

use InvalidArgumentException;

final readonly class FareConditionSnapshot
{
    /** @param list<array<string, mixed>> $terms */
    public function __construct(public array $terms = [])
    {
        $required = ['key', 'value', 'certainty', 'source_reference', 'fetched_at', 'verified_at', 'expires_at', 'verification_status'];
        foreach ($terms as $term) {
            if (array_diff($required, array_keys($term)) !== []) {
                throw new InvalidArgumentException('Fare condition source and freshness metadata are required.');
            }
            if (! in_array($term['certainty'], ['EXACT', 'ESTIMATED', 'UNKNOWN'], true)) {
                throw new InvalidArgumentException('Invalid fare condition certainty.');
            }
            if ($term['certainty'] === 'UNKNOWN' && $term['value'] !== null) {
                throw new InvalidArgumentException('Unknown fare conditions cannot carry a value.');
            }
            if (! in_array($term['verification_status'], ['FRESH', 'STALE', 'EXPIRED', 'UNVERIFIED'], true)) {
                throw new InvalidArgumentException('Invalid fare condition verification status.');
            }
        }
    }

    /** @return array{terms: list<array<string, mixed>>, baggage: array<string, mixed>} */
    public function toContract(BaggageAllowance $baggage): array
    {
        return [
            'terms' => $this->terms,
            'baggage' => $baggage->toContract(),
        ];
    }

    public function termsFingerprint(): string
    {
        return json_encode($this->terms, JSON_THROW_ON_ERROR);
    }
}
