<?php

namespace App\Modules\FlightSearch\Infrastructure\Adapters;

use App\Modules\FlightSearch\Contracts\FlightRepriceGateway;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\ProviderTimeout;

final class UnavailableFlightRepriceGateway implements FlightRepriceGateway
{
    public function reprice(string $providerId, FlightOffer $offer): FlightOffer
    {
        throw new ProviderTimeout('No production provider adapter is configured.');
    }
}
