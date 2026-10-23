<?php

namespace App\Modules\FlightSearch\Contracts;

/** OP-getTripRequestFlightSelection read side (v3.13) for other modules and pages, e.g. "Seyahat Planım". */
interface FlightSelectionReader
{
    /**
     * SCH-TripRequestFlightSelection v3.13, or null when nothing is selected. Expiry is evaluated on every read
     * (TRN-FSEL-03), so a returned CURRENT selection has not passed expires_at.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $tripRequestId): ?array;
}
