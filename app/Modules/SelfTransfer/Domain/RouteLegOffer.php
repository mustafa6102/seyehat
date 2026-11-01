<?php

namespace App\Modules\SelfTransfer\Domain;

use App\Modules\FlightSearch\Domain\FlightOffer;
use InvalidArgumentException;

final readonly class RouteLegOffer
{
    public function __construct(
        public string $originId,
        public string $destinationId,
        public FlightOffer $offer,
    ) {
        if ($originId === '' || $destinationId === '' || $originId === $destinationId) {
            throw new InvalidArgumentException('Route leg endpoints must be distinct, non-empty ids.');
        }
    }

    public function departureTimestamp(): int
    {
        return $this->offer->segments[0]->departure->utcInstant->getTimestamp();
    }

    public function arrivalTimestamp(): int
    {
        return $this->offer->segments[array_key_last($this->offer->segments)]->arrival->utcInstant->getTimestamp();
    }
}
