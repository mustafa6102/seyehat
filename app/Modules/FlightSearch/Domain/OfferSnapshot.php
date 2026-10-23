<?php

namespace App\Modules\FlightSearch\Domain;

use DateTimeImmutable;

final readonly class OfferSnapshot
{
    public function __construct(
        public FlightOffer $offer,
        public DateTimeImmutable $capturedAt,
    ) {}

    /** @return array{offer: array<string, mixed>, fare_conditions: array<string, mixed>, captured_at: string} */
    public function toContract(): array
    {
        return [
            'offer' => $this->offer->toContract($this->capturedAt),
            'fare_conditions' => $this->offer->fareConditions->toContract($this->offer->baggage),
            'captured_at' => $this->capturedAt->format(DATE_ATOM),
        ];
    }
}
