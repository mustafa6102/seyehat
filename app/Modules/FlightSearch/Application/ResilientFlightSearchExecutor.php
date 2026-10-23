<?php

namespace App\Modules\FlightSearch\Application;

use App\Modules\FlightSearch\Contracts\FlightProviderAdapter;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightSearchOutcome;
use App\Modules\FlightSearch\Contracts\ScreensFlightCriteria;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use InvalidArgumentException;

/**
 * Provider fan-out where each provider runs behind its own guard (PLT-REL-001): a failing,
 * timing-out or rate-limited provider is isolated and the others still contribute offers.
 */
final readonly class ResilientFlightSearchExecutor implements FlightSearchExecutor
{
    /** @param list<FlightProviderAdapter> $adapters */
    public function __construct(
        private array $adapters,
        private ProviderCallGuard $guard,
        private FlightOfferAggregator $aggregator,
    ) {
        if ($adapters === []) {
            throw new InvalidArgumentException('At least one flight provider adapter is required.');
        }
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function execute(FlightSearchCriteria $criteria, callable $publishPartial): FlightSearchOutcome
    {
        $collected = [];
        $succeeded = 0;
        $failures = [];

        foreach ($this->adapters as $adapter) {
            if ($adapter instanceof ScreensFlightCriteria && ! $adapter->supportsCriteria($criteria)) {
                $failures[] = ProviderFailureKind::UNSUPPORTED_CRITERIA;

                continue;
            }
            try {
                $offers = $this->guard->call(
                    $adapter->providerId(),
                    fn (int $timeoutMs): array => $adapter->search($criteria, $timeoutMs),
                );
            } catch (ProviderFailure $failure) {
                $failures[] = $failure->kind;

                continue;
            }

            $succeeded++;
            array_push($collected, ...$offers);
            $publishPartial($this->aggregator->aggregate($this->detached($collected)));
        }

        return new FlightSearchOutcome($this->aggregator->aggregate($collected), $succeeded, $failures);
    }

    /**
     * Aggregation merges duplicates in place, so intermediate snapshots work on deep copies
     * to keep repeated aggregation from duplicating sources.
     *
     * @param  list<FlightOffer>  $offers
     * @return list<FlightOffer>
     */
    private function detached(array $offers): array
    {
        return unserialize(serialize($offers));
    }
}
