<?php

namespace App\Modules\SelfTransfer\Application;

use App\Modules\CandidateGenerator\Application\BoundedCandidateGenerator;
use App\Modules\CandidateGenerator\Application\IndependentLegCriteriaFactory;
use App\Modules\CandidateGenerator\Contracts\CandidateKind;
use App\Modules\CandidateGenerator\Contracts\CandidateOutcome;
use App\Modules\CandidateGenerator\Contracts\CandidateSeedSource;
use App\Modules\CandidateGenerator\Contracts\PruningEvidence;
use App\Modules\CandidateGenerator\Contracts\SearchBudgetPolicy;
use App\Modules\CandidateGenerator\Infrastructure\ContextualDuffelCandidateLegSearch;
use App\Modules\CandidateGenerator\Infrastructure\DuffelIndependentLegAdapter;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Infrastructure\Duffel\DuffelFlightProviderAdapter;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use Illuminate\Contracts\Config\Repository;

final readonly class SelfTransferDiscoveryOrchestrator
{
    public function __construct(
        private Repository $config,
        private CandidateSeedSource $seeds,
        private PruningEvidence $evidence,
        private ProviderCapabilityMatrix $capabilities,
        private SearchBudgetPolicy $budgets,
        private IndependentLegCriteriaFactory $criteria,
        private DuffelIndependentLegAdapter $duffel,
        private SelfTransferRouteMatcher $matcher,
        private SelfTransferRouteRanker $ranker,
    ) {}

    /**
     * @param list<FlightOffer> $directOffers
     * @return array<string, mixed>|null null means the version gate is closed and existing search output is untouched
     */
    public function discover(FlightSearchCriteria $original, array $directOffers): ?array
    {
        if (! $this->active()) {
            return null;
        }
        $hubPlaces = $this->config->get('self_transfer.hub_places');
        $minimum = $this->config->get('self_transfer.minimum_connection_seconds');
        $limit = $this->config->get('self_transfer.max_results');
        if (! is_array($hubPlaces) || ! is_int($minimum) || $minimum < 0 || ! is_int($limit) || $limit < 1
            || ! $this->versioned('self_transfer.connection_config_version')
            || ! $this->versioned('self_transfer.results_config_version')) {
            return ['routes' => [], 'completeness' => 'PARTIAL', 'request_count' => 0, 'blockers' => ['CFG-STF-ACTIVATION']];
        }

        $originId = $this->aviationId($original->originPlace);
        $destinationId = $this->aviationId($original->destinationPlace);
        if ($originId === null || $destinationId === null) {
            return ['routes' => [], 'completeness' => 'PARTIAL', 'request_count' => 0, 'blockers' => ['GAP-FLT-CRITERIA-001']];
        }

        $legSearch = new ContextualDuffelCandidateLegSearch(
            $original,
            $originId,
            $destinationId,
            $hubPlaces,
            $directOffers,
            $this->criteria,
            $this->duffel,
        );
        $generation = (new BoundedCandidateGenerator(
            $this->seeds,
            $this->evidence,
            $this->capabilities,
            $this->budgets,
            $legSearch,
        ))->generate($originId, $destinationId, DuffelFlightProviderAdapter::PROVIDER_ID);

        $routes = [];
        foreach ($generation->evaluations as $evaluation) {
            if ($evaluation->candidate->kind !== CandidateKind::ONE_HUB || $evaluation->outcome !== CandidateOutcome::QUERIED) {
                continue;
            }
            $hubId = $evaluation->candidate->hubId;
            if ($hubId === null) {
                continue;
            }
            array_push($routes, ...$this->matcher->match(
                $legSearch->firstOffers($hubId),
                $legSearch->secondOffers($hubId),
                $minimum,
                $limit,
            ));
        }
        $routes = $this->ranker->rank($routes, $limit);

        return [
            'routes' => array_map(static fn ($route): array => $route->toPresentation(), $routes),
            'completeness' => $generation->completeness(),
            'request_count' => $generation->requestCount,
            'reason_codes' => $generation->reasonCodes(),
            'blockers' => $generation->blockers,
        ];
    }

    private function active(): bool
    {
        if ($this->config->get('self_transfer.provider_leg_search_active') !== true) {
            return false;
        }
        $required = $this->config->get('self_transfer.required_contract_version');
        $path = base_path('registry/active_contract.json');
        $active = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_string($required) && is_array($active) && ($active['version'] ?? null) === $required;
    }

    private function versioned(string $key): bool
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '';
    }

    /** @param array<string, mixed> $place */
    private function aviationId(array $place): ?string
    {
        $identity = $place['aviation_identity'] ?? null;

        return is_array($identity) && is_string($identity['code'] ?? null) ? $identity['code'] : null;
    }
}
