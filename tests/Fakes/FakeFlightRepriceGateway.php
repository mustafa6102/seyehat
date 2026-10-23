<?php

namespace Tests\Fakes;

use App\Modules\FlightSearch\Contracts\FlightRepriceGateway;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\ProviderTimeout;
use App\Modules\FlightSearch\Domain\RepriceFailed;

final readonly class FakeFlightRepriceGateway implements FlightRepriceGateway
{
    public function __construct(
        private ?FlightOffer $result = null,
        private bool $timesOut = false,
        private bool $fails = false,
    ) {}

    public function reprice(string $providerId, FlightOffer $offer): FlightOffer
    {
        if ($this->timesOut) {
            throw new ProviderTimeout;
        }
        if ($this->fails) {
            throw new RepriceFailed;
        }

        return $this->result ?? $offer;
    }
}
