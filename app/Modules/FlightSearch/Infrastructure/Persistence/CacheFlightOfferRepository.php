<?php

namespace App\Modules\FlightSearch\Infrastructure\Persistence;

use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

final readonly class CacheFlightOfferRepository implements FlightOfferRepository
{
    public function __construct(private CacheRepository $cache) {}

    public function save(FlightOffer $offer): void
    {
        if ($offer->expiresAt === null) {
            return;
        }

        $this->cache->put($this->key($offer->providerOfferId), $offer, $offer->expiresAt);
    }

    public function find(string $offerId): ?FlightOffer
    {
        $offer = $this->cache->get($this->key($offerId));

        return $offer instanceof FlightOffer ? $offer : null;
    }

    private function key(string $offerId): string
    {
        return 'flight-offer:'.hash('sha256', $offerId);
    }
}
