<?php

namespace App\Modules\FlightSearch\Contracts;

use App\Modules\FlightSearch\Domain\FlightOffer;

interface FlightSearchExecutor
{
    /** False while no production provider adapter is configured (OD-001). */
    public function isAvailable(): bool;

    /** @param callable(list<FlightOffer>): void $publishPartial */
    public function execute(FlightSearchCriteria $criteria, callable $publishPartial): FlightSearchOutcome;
}
