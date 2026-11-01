<?php

namespace App\Modules\CandidateGenerator\Infrastructure;

use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Infrastructure\Duffel\DuffelFlightProviderAdapter;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\SelfTransfer\Domain\RouteLegOffer;
use Illuminate\Contracts\Config\Repository;
use LogicException;

/**
 * Inactive production boundary for RCG leg searches. Criteria produced here are accepted by the existing Duffel
 * adapter without a second request mapper. Provider execution stays impossible until the active contract changes.
 */
final readonly class DuffelIndependentLegAdapter
{
    public function __construct(
        private Repository $config,
        private ProviderCapabilityMatrix $capabilities,
        private DuffelFlightProviderAdapter $duffel,
    ) {}

    /** @return list<FlightOffer> */
    public function search(FlightSearchCriteria $criteria): array
    {
        if ($this->config->get('self_transfer.provider_leg_search_active') !== true) {
            throw new LogicException('Independent provider leg search is inactive until the versioned RCG/STF contract is active.');
        }
        $capability = $this->capabilities->productionCapability(DuffelFlightProviderAdapter::PROVIDER_ID, 'search');
        if (! $capability->supported || $capability->status !== 'FRESH'
            || $capability->evidence === null || trim($capability->evidence) === '') {
            throw new LogicException('Fresh Duffel search capability evidence is required.');
        }

        return $this->duffel->search($criteria);
    }

    /**
     * Wraps only real, already normalized provider offers with the canonical endpoints used to request them.
     *
     * @param list<FlightOffer> $offers
     * @return list<RouteLegOffer>
     */
    public function normalize(string $originId, string $destinationId, array $offers): array
    {
        return array_map(
            static fn (FlightOffer $offer): RouteLegOffer => new RouteLegOffer($originId, $destinationId, $offer),
            $offers,
        );
    }
}
