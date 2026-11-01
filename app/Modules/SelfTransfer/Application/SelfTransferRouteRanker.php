<?php

namespace App\Modules\SelfTransfer\Application;

use App\Modules\SelfTransfer\Domain\SelfTransferRoute;

final class SelfTransferRouteRanker
{
    /** @param list<SelfTransferRoute> $routes @return list<SelfTransferRoute> */
    public function rank(array $routes, int $limit): array
    {
        usort($routes, static function (SelfTransferRoute $left, SelfTransferRoute $right): int {
            $known = ($left->knownTotal === null) <=> ($right->knownTotal === null);
            if ($known !== 0) {
                return $known;
            }
            if ($left->knownTotal !== null && $right->knownTotal !== null) {
                $currency = $left->knownTotal->currency <=> $right->knownTotal->currency;
                if ($currency !== 0) {
                    return $currency;
                }
                $price = $left->knownTotal->minorUnits <=> $right->knownTotal->minorUnits;
                if ($price !== 0) {
                    return $price;
                }
            }
            $duration = $left->totalDurationSeconds <=> $right->totalDurationSeconds;

            return $duration !== 0 ? $duration : $left->deduplicationKey() <=> $right->deduplicationKey();
        });

        return array_slice($routes, 0, max(0, $limit));
    }
}
