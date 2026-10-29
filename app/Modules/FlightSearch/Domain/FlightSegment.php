<?php

namespace App\Modules\FlightSearch\Domain;

use InvalidArgumentException;

final class FlightSegment
{
    /**
     * v3.23 (OD-022 D1): OUTBOUND for the provider's first slice, INBOUND for the second. Deliberately not readonly
     * and defaulted: segments cached before v3.23 unserialize without this property and must read as OUTBOUND (D2).
     */
    public string $direction = 'OUTBOUND';

    /**
     * @param  list<string>  $marketingFlightReferences
     */
    public function __construct(
        public readonly string $operatingCarrier,
        public readonly string $operatingFlightNumber,
        public array $marketingFlightReferences,
        public readonly string $carrierModel,
        public readonly SegmentTime $departure,
        public readonly SegmentTime $arrival,
        string $direction = 'OUTBOUND',
    ) {
        if (! in_array($carrierModel, ['FULL_SERVICE', 'LCC', 'HYBRID', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('Invalid CarrierModel.');
        }
        if (! in_array($direction, ['OUTBOUND', 'INBOUND'], true)) {
            throw new InvalidArgumentException('Invalid segment direction.');
        }
        $this->direction = $direction;
        $this->marketingFlightReferences = self::sortedUnique($marketingFlightReferences);
    }

    public function durationMinutes(): int
    {
        return intdiv(
            $this->arrival->utcInstant->getTimestamp() - $this->departure->utcInstant->getTimestamp(),
            60,
        );
    }

    public function physicalFingerprint(int $sequence): string
    {
        return implode('|', [
            $this->operatingCarrier,
            $this->operatingFlightNumber,
            substr($this->departure->localDateTime, 0, 10),
            (string) $sequence,
        ]);
    }

    public function mergeMarketingReferences(self $other): void
    {
        $this->marketingFlightReferences = self::sortedUnique([
            ...$this->marketingFlightReferences,
            ...$other->marketingFlightReferences,
        ]);
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'direction' => $this->direction,
            'operating_carrier' => $this->operatingCarrier,
            'operating_flight_number' => $this->operatingFlightNumber,
            'marketing_flight_references' => $this->marketingFlightReferences,
            'carrier_model' => $this->carrierModel,
            'departure' => $this->departure->toContract(),
            'arrival' => $this->arrival->toContract(),
            'duration_minutes' => $this->durationMinutes(),
        ];
    }

    /** @param list<string> $values @return list<string> */
    private static function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
