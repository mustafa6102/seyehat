<?php

namespace App\Modules\FlightSearch\Contracts;

/**
 * Optional adapter capability: rejects canonical criteria the provider integration cannot express BEFORE the
 * resilience guard, so a request that never reaches the provider neither consumes a rate slot nor charges the circuit.
 */
interface ScreensFlightCriteria
{
    public function supportsCriteria(FlightSearchCriteria $criteria): bool;
}
