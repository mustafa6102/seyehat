<?php

namespace App\Modules\FlightSearch\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CalculatedOfferPrice
{
    /** @param list<string> $unknownComponents */
    public function __construct(
        public ?Money $value,
        public ?Money $knownLowerBound,
        public array $unknownComponents,
        public string $completeness,
        public string $certainty,
        public ?string $fxSnapshotId,
        public array $inputRefs,
        public string $calculationVersion,
        public DateTimeImmutable $calculatedAt,
    ) {
        if (! in_array($certainty, ['EXACT', 'ESTIMATED', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('Invalid price certainty.');
        }
        if (! in_array($completeness, ['COMPLETE', 'PARTIAL'], true)) {
            throw new InvalidArgumentException('Invalid price completeness.');
        }
        if ($completeness === 'COMPLETE' && ($value === null || $knownLowerBound !== null || $unknownComponents !== [])) {
            throw new InvalidArgumentException('COMPLETE price requires value and no unknown components.');
        }
        if ($completeness === 'PARTIAL' && ($value !== null || $knownLowerBound === null || $unknownComponents === [])) {
            throw new InvalidArgumentException('PARTIAL price requires a lower bound and unknown components.');
        }
        if ($certainty === 'UNKNOWN' && $value !== null) {
            throw new InvalidArgumentException('UNKNOWN price cannot carry a numeric value.');
        }
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'value' => $this->value?->toContract(),
            'certainty' => $this->certainty,
            'completeness' => $this->completeness,
            'known_lower_bound' => $this->knownLowerBound?->toContract(),
            'unknown_components' => $this->unknownComponents,
            'fx_snapshot_id' => $this->fxSnapshotId,
            'input_refs' => $this->inputRefs,
            'calculation_version' => $this->calculationVersion,
            'calculated_at' => $this->calculatedAt->format(DATE_ATOM),
        ];
    }
}
