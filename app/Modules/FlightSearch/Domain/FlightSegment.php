<?php

namespace App\Modules\FlightSearch\Domain;

use InvalidArgumentException;

final class FlightSegment
{
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
    ) {
        if (! in_array($carrierModel, ['FULL_SERVICE', 'LCC', 'HYBRID', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('Invalid CarrierModel.');
        }
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
