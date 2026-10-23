<?php

namespace App\Modules\CandidateGenerator\Contracts;

/**
 * Provider search for one candidate leg. No production implementation exists: FlightSearchCriteria is
 * built only from a SEARCHABLE TripRequest, so leg-level (origin→hub) criteria have no contract yet.
 */
interface CandidateLegSearch
{
    public function search(CandidateLeg $leg, string $providerId): LegSearchResult;
}
