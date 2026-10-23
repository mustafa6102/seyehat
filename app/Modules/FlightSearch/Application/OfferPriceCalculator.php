<?php

namespace App\Modules\FlightSearch\Application;

use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\CalculatedOfferPrice;
use App\Modules\FlightSearch\Domain\Money;
use DateTimeImmutable;
use InvalidArgumentException;

final class OfferPriceCalculator
{
    /** @param list<string> $inputRefs */
    public function calculate(
        Money $basePrice,
        string $baseCompleteness,
        BaggageAllowance $baggage,
        string $certainty = 'EXACT',
        array $inputRefs = ['base_price'],
        ?DateTimeImmutable $calculatedAt = null,
    ): CalculatedOfferPrice {
        if (! in_array($baseCompleteness, ['COMPLETE', 'PARTIAL'], true)) {
            throw new InvalidArgumentException('Invalid price completeness.');
        }

        $total = $basePrice;
        $completeness = $baseCompleteness;
        $unknownComponents = $baseCompleteness === 'PARTIAL' ? ['BASE_PRICE_PARTIAL'] : [];

        foreach ($baggage->fees as $fee) {
            if (! $fee->mandatory) {
                continue;
            }
            if (! $fee->isVerified()) {
                $completeness = 'PARTIAL';
                $certainty = 'UNKNOWN';
                $unknownComponents[] = $fee->component;

                continue;
            }
            if ($fee->certainty === 'ESTIMATED' && $certainty !== 'UNKNOWN') {
                $certainty = 'ESTIMATED';
            }
            $total = $total->add($fee->amount);
        }

        return new CalculatedOfferPrice(
            value: $completeness === 'COMPLETE' ? $total : null,
            knownLowerBound: $completeness === 'PARTIAL' ? $total : null,
            unknownComponents: array_values(array_unique($unknownComponents)),
            completeness: $completeness,
            certainty: $certainty,
            fxSnapshotId: null,
            inputRefs: $inputRefs,
            calculationVersion: 'v3.3',
            calculatedAt: $calculatedAt ?? new DateTimeImmutable('now'),
        );
    }
}
