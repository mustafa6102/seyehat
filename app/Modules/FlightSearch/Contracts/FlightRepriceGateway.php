<?php

namespace App\Modules\FlightSearch\Contracts;

use App\Modules\FlightSearch\Domain\FlightOffer;

interface FlightRepriceGateway
{
    /** Provider SDK/raw values must be normalized before returning. */
    public function reprice(string $providerId, FlightOffer $offer): FlightOffer;
}
