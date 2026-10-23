<?php

namespace App\Modules\Api\Contracts;

/**
 * TRIP-001 v3.9 journey (RULE-TRIP-001-04, RULE-TRIP-001-05): journey_type ONE_WAY | ROUND_TRIP with a
 * direction-specific outbound_date and, only for ROUND_TRIP, return_date. The journey type is never inferred
 * from which dates are present. A round trip is invalid when latest(return_date) < earliest(outbound_date).
 */
final readonly class TripJourney
{
    public const ONE_WAY = 'ONE_WAY';

    public const ROUND_TRIP = 'ROUND_TRIP';

    private function __construct(
        public string $type,
        public TravelDate $outbound,
        public ?TravelDate $return,
    ) {}

    /**
     * Fields that are present (non-null) but violate the contract.
     *
     * @return list<string>
     */
    public static function invalidFields(mixed $journeyType, mixed $outboundDate, mixed $returnDate): array
    {
        $invalid = [];
        if ($journeyType !== null && ! in_array($journeyType, [self::ONE_WAY, self::ROUND_TRIP], true)) {
            $invalid[] = 'journey_type';
        }
        $outbound = TravelDate::fromContract($outboundDate);
        if ($outboundDate !== null && $outbound === null) {
            $invalid[] = 'outbound_date';
        }
        if ($returnDate !== null) {
            $return = TravelDate::fromContract($returnDate);
            if ($journeyType !== self::ROUND_TRIP || $return === null || ($outbound !== null && $return->latest < $outbound->earliest)) {
                $invalid[] = 'return_date';
            }
        }

        return $invalid;
    }

    /**
     * Required fields that are absent (null) for a searchable request.
     *
     * @return list<string>
     */
    public static function missingFields(mixed $journeyType, mixed $outboundDate, mixed $returnDate): array
    {
        return array_keys(array_filter([
            'journey_type' => $journeyType === null,
            'outbound_date' => $outboundDate === null,
            'return_date' => $journeyType === self::ROUND_TRIP && $returnDate === null,
        ]));
    }

    /** A complete and valid journey, or null. */
    public static function fromContract(mixed $journeyType, mixed $outboundDate, mixed $returnDate): ?self
    {
        $outbound = TravelDate::fromContract($outboundDate);
        if ($outbound === null || self::invalidFields($journeyType, $outboundDate, $returnDate) !== []
            || self::missingFields($journeyType, $outboundDate, $returnDate) !== []) {
            return null;
        }

        return new self((string) $journeyType, $outbound, TravelDate::fromContract($returnDate));
    }

    /** @return array{journey_type: string, outbound_date: array<string, string>, return_date?: array<string, string>} */
    public function toContract(): array
    {
        $journey = ['journey_type' => $this->type, 'outbound_date' => $this->outbound->toContract()];

        return $this->return === null ? $journey : $journey + ['return_date' => $this->return->toContract()];
    }
}
