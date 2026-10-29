<?php

namespace App\Modules\TripBuilder\Application;

use App\Modules\Api\Contracts\TravelDate;
use App\Modules\Api\Contracts\TripJourney;
use App\Modules\Api\Contracts\TripRequestView;
use DateTimeImmutable;
use DateTimeZone;

/**
 * SCH-TripPlan.day_frame v3.18 (RULE-DAY-001-04, OD-029 A): the plan days, derived on every read and never stored. Only a
 * SEARCHABLE v3.9 ROUND_TRIP TripRequest with EXACT outbound and return dates and a valid destination IANA timezone has
 * days: every calendar date from outbound to return, both inclusive, as local dates of the destination. Nothing else
 * (ONE_WAY, FLEXIBLE ranges, legacy date_window) is turned into days; no date is picked or invented.
 */
final readonly class DayFrame
{
    public const AVAILABLE = 'AVAILABLE';

    public const UNAVAILABLE = 'UNAVAILABLE';

    /** @param  list<string>  $days */
    private function __construct(
        public string $status,
        public ?string $ianaTimezone,
        public array $days,
        public ?string $unavailableReason,
    ) {}

    public static function from(?TripRequestView $tripRequest): self
    {
        if ($tripRequest === null || $tripRequest->status !== 'SEARCHABLE') {
            return self::unavailable('TRIP_REQUEST_INCOMPLETE');
        }
        if ($tripRequest->isLegacyDateModel()) {
            return self::unavailable('LEGACY_DATE_MODEL');
        }
        if ($tripRequest->journeyType !== TripJourney::ROUND_TRIP) {
            return self::unavailable('NOT_ROUND_TRIP');
        }
        $outbound = TravelDate::fromContract($tripRequest->outboundDate);
        $return = TravelDate::fromContract($tripRequest->returnDate);
        if ($outbound?->type !== TravelDate::EXACT || $return?->type !== TravelDate::EXACT || $return->earliest < $outbound->earliest) {
            return self::unavailable('DATES_NOT_EXACT');
        }
        $timezone = $tripRequest->destinationPlace['iana_timezone'] ?? null;
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return self::unavailable('DESTINATION_TIMEZONE_UNKNOWN');
        }

        // Calendar arithmetic only (date-only values); the timezone labels whose local days these are.
        $days = [];
        $utc = new DateTimeZone('UTC');
        for ($day = new DateTimeImmutable($outbound->earliest, $utc); $day->format('Y-m-d') <= $return->earliest; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return new self(self::AVAILABLE, $timezone, $days, null);
    }

    public function contains(string $date): bool
    {
        return in_array($date, $this->days, true);
    }

    /** @return array{status: string, iana_timezone: string|null, days: list<string>, unavailable_reason: string|null} */
    public function toContract(): array
    {
        return ['status' => $this->status, 'iana_timezone' => $this->ianaTimezone, 'days' => $this->days, 'unavailable_reason' => $this->unavailableReason];
    }

    private static function unavailable(string $reason): self
    {
        return new self(self::UNAVAILABLE, null, [], $reason);
    }
}
