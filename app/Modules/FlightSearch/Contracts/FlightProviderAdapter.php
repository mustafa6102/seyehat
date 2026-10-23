<?php

namespace App\Modules\FlightSearch\Contracts;

use App\Modules\FlightSearch\Domain\FlightOffer;

interface FlightProviderAdapter
{
    public function providerId(): string;

    /**
     * @param  FlightSearchCriteria  $criteria  canonical criteria; the adapter must not derive semantics that OD-022 leaves OPEN
     * @param  int|null  $timeoutMs  provider-scoped timeout the adapter must apply to its transport
     * @return list<FlightOffer>
     */
    public function search(FlightSearchCriteria $criteria, ?int $timeoutMs = null): array;
}
