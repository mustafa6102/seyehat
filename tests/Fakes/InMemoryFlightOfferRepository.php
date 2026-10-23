<?php

namespace Tests\Fakes;

use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;

final class InMemoryFlightOfferRepository implements FlightOfferRepository
{
    /** @var array<string, FlightOffer> */
    private array $offers = [];

    /** @param list<FlightOffer> $offers */
    public function __construct(array $offers = [])
    {
        foreach ($offers as $offer) {
            $this->save($offer);
        }
    }

    public function save(FlightOffer $offer): void
    {
        $this->offers[$offer->providerOfferId] = $offer;
    }

    public function find(string $offerId): ?FlightOffer
    {
        return $this->offers[$offerId] ?? null;
    }
}
