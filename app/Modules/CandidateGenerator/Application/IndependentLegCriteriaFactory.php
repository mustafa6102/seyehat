<?php

namespace App\Modules\CandidateGenerator\Application;

use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Domain\FlightOffer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class IndependentLegCriteriaFactory
{
    /** @param array<string, mixed> $hubPlace */
    public function firstLeg(FlightSearchCriteria $original, array $hubPlace): FlightSearchCriteria
    {
        $date = $original->outboundDate['date'] ?? null;
        if (($original->outboundDate['type'] ?? null) !== 'EXACT' || ! is_string($date)) {
            throw new InvalidArgumentException('First-leg search requires the original exact outbound date.');
        }

        return FlightSearchCriteria::forOneWayLeg($original, $original->originPlace, $hubPlace, $date);
    }

    /**
     * Maps a second-leg criterion only when an actual candidate departure instant is already available. The date is
     * calculated in the hub's declared IANA timezone and must follow the actual first-offer arrival instant.
     *
     * @param array<string, mixed> $hubPlace
     */
    public function secondLeg(
        FlightSearchCriteria $original,
        array $hubPlace,
        FlightOffer $actualFirstOffer,
        DateTimeImmutable $actualSecondDepartureUtc,
    ): FlightSearchCriteria {
        $last = $actualFirstOffer->segments[array_key_last($actualFirstOffer->segments)];
        if ($actualSecondDepartureUtc->getOffset() !== 0 || $actualSecondDepartureUtc <= $last->arrival->utcInstant) {
            throw new InvalidArgumentException('Second-leg departure must be an actual UTC instant after first-leg arrival.');
        }
        $timezone = $hubPlace['iana_timezone'] ?? null;
        if (! is_string($timezone) || $timezone === '') {
            throw new InvalidArgumentException('Hub IANA timezone is required.');
        }

        $date = $actualSecondDepartureUtc->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');

        return FlightSearchCriteria::forOneWayLeg($original, $hubPlace, $original->destinationPlace, $date);
    }

    /** @param array<string, mixed> $hubPlace */
    public function secondLegFromArrival(
        FlightSearchCriteria $original,
        array $hubPlace,
        FlightOffer $actualFirstOffer,
    ): FlightSearchCriteria {
        $last = $actualFirstOffer->segments[array_key_last($actualFirstOffer->segments)];
        $timezone = $hubPlace['iana_timezone'] ?? null;
        if (! is_string($timezone) || $timezone === '') {
            throw new InvalidArgumentException('Hub IANA timezone is required.');
        }
        $date = $last->arrival->utcInstant->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');

        return FlightSearchCriteria::forOneWayLeg($original, $hubPlace, $original->destinationPlace, $date);
    }
}
