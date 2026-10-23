<?php

namespace App\Modules\Accommodation\Contracts;

/** OP-getTripRequestAccommodationSelection read side (v3.14) for other modules and pages, e.g. "Seyahat Planım". */
interface AccommodationSelectionReader
{
    /**
     * SCH-TripRequestAccommodationSelection v3.14, or null when nothing is selected. Expiry is evaluated on every read
     * (TRN-ACCSEL-03).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $tripRequestId): ?array;
}
