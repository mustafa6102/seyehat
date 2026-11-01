<?php

namespace App\Modules\SelfTransfer\Application;

use App\Modules\FlightSearch\Domain\Money;
use App\Modules\SelfTransfer\Domain\RouteLegOffer;
use App\Modules\SelfTransfer\Domain\SelfTransferRoute;

final class SelfTransferRouteMatcher
{
    /**
     * Matches only supplied offers. Airport discovery and provider calls deliberately stay outside this component.
     *
     * @param  list<RouteLegOffer>  $firstLegs
     * @param  list<RouteLegOffer>  $secondLegs
     * @return list<SelfTransferRoute>
     */
    public function match(array $firstLegs, array $secondLegs, int $minimumConnectionSeconds, int $limit): array
    {
        if ($minimumConnectionSeconds < 0 || $limit < 1) {
            return [];
        }

        $routes = [];
        $seen = [];
        foreach ($firstLegs as $first) {
            foreach ($secondLegs as $second) {
                if ($first->destinationId !== $second->originId || $first->originId === $second->destinationId) {
                    continue;
                }
                $connection = $second->departureTimestamp() - $first->arrivalTimestamp();
                if ($connection < $minimumConnectionSeconds) {
                    continue;
                }
                if ($first->offer->state() !== 'CURRENT' || $second->offer->state() !== 'CURRENT') {
                    continue;
                }

                [$total, $unknown] = $this->price($first, $second);
                $reasons = ['SEPARATE_TICKETS', 'MISSED_CONNECTION_NOT_PROTECTED'];
                if ($first->offer->baggage->checkedBag !== false || $second->offer->baggage->checkedBag !== false) {
                    $reasons[] = 'BAGGAGE_RECHECK_REQUIRES_VERIFICATION';
                }
                $reasons[] = 'IMMIGRATION_TRANSIT_REQUIRES_VERIFICATION';
                $route = new SelfTransferRoute(
                    id: hash('sha256', $first->offer->providerOfferId.'|'.$second->offer->providerOfferId),
                    legs: [$first, $second],
                    knownTotal: $total,
                    unknownCosts: $unknown,
                    totalDurationSeconds: $second->arrivalTimestamp() - $first->departureTimestamp(),
                    connectionDurationSeconds: $connection,
                    riskLevel: 'UNKNOWN',
                    reasonCodes: $reasons,
                );
                $key = $route->deduplicationKey();
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $routes[] = $route;
                }
            }
        }

        return array_slice($routes, 0, $limit);
    }

    /** @return array{0: ?Money, 1: list<string>} */
    private function price(RouteLegOffer $first, RouteLegOffer $second): array
    {
        $unknown = ['GROUND_TRANSFER_COST'];
        if ($first->offer->price->currency !== $second->offer->price->currency
            || $first->offer->priceCompleteness !== 'COMPLETE'
            || $second->offer->priceCompleteness !== 'COMPLETE') {
            return [null, [...$unknown, 'INCOMPATIBLE_OR_INCOMPLETE_FLIGHT_PRICE']];
        }

        foreach ([$first, $second] as $leg) {
            if ($leg->offer->baggage->checkedBag !== false && $leg->offer->baggage->fees === []) {
                $unknown[] = 'CHECKED_BAGGAGE_COST';
                break;
            }
        }

        return [$first->offer->price->add($second->offer->price), array_values(array_unique($unknown))];
    }
}
