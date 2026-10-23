<?php

namespace App\Modules\FlightSearch\Domain;

interface FlightOfferRepository
{
    public function save(FlightOffer $offer): void;

    public function find(string $offerId): ?FlightOffer;
}
