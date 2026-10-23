<?php

namespace App\Modules\FlightSearch\Application;

use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightSearchOutcome;

/**
 * Production gate in front of real provider adapters: criteria whose locations cannot be represented
 * for a flight provider (no valid aviation identity, station, kind mismatch) never reach an adapter (OD-022, v3.8).
 */
final readonly class LocationGatedFlightSearchExecutor implements FlightSearchExecutor
{
    public function __construct(private FlightSearchExecutor $inner) {}

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    /** @throws UnsupportedFlightSearchLocation */
    public function execute(FlightSearchCriteria $criteria, callable $publishPartial): FlightSearchOutcome
    {
        $blocker = $criteria->locationBlockerTr();
        if ($blocker !== null) {
            throw new UnsupportedFlightSearchLocation($blocker);
        }

        return $this->inner->execute($criteria, $publishPartial);
    }
}
