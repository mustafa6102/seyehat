<?php

namespace App\Modules\FlightSearch\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SegmentTime
{
    public function __construct(
        public string $localDateTime,
        public string $ianaTimezone,
        public DateTimeImmutable $utcInstant,
    ) {
        if (DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $localDateTime) === false) {
            throw new InvalidArgumentException('Local datetime must use Y-m-d\\TH:i:s.');
        }
        try {
            new DateTimeZone($ianaTimezone);
        } catch (\Exception) {
            throw new InvalidArgumentException('Segment timezone must be a valid IANA timezone.');
        }
        if ($utcInstant->getOffset() !== 0) {
            throw new InvalidArgumentException('Segment UTC instant must have a zero UTC offset.');
        }
    }

    /** @return array{local_datetime: string, iana_timezone: string, utc_instant: string} */
    public function toContract(): array
    {
        return [
            'local_datetime' => $this->localDateTime,
            'iana_timezone' => $this->ianaTimezone,
            'utc_instant' => $this->utcInstant->format('Y-m-d\TH:i:sP'),
        ];
    }
}
