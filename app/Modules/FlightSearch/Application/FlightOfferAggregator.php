<?php

namespace App\Modules\FlightSearch\Application;

use App\Modules\FlightSearch\Domain\FlightOffer;

final class FlightOfferAggregator
{
    /** @param list<FlightOffer> $offers @return list<FlightOffer> */
    public function aggregate(array $offers): array
    {
        $deduplicated = [];
        foreach ($offers as $offer) {
            $key = $offer->deduplicationKey();
            if (isset($deduplicated[$key])) {
                $deduplicated[$key]->merge($offer);
            } else {
                $deduplicated[$key] = $offer;
            }
        }

        $result = array_values($deduplicated);
        usort($result, function (FlightOffer $a, FlightOffer $b): int {
            $qualityA = $this->preferenceQuality($a);
            $qualityB = $this->preferenceQuality($b);

            $priceComparison = $a->price->currency === $b->price->currency
                ? $a->price->minorUnits <=> $b->price->minorUnits
                : 0;

            return $qualityB <=> $qualityA
                ?: $priceComparison
                ?: $a->deduplicationKey() <=> $b->deduplicationKey();
        });

        return $result;
    }

    private function preferenceQuality(FlightOffer $offer): int
    {
        return ($offer->bookable ? 4 : 0)
            + ($offer->priceCompleteness === 'COMPLETE' ? 2 : 0)
            + ($offer->priceCertainty === 'EXACT' ? 1 : 0);
    }
}
