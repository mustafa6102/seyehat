<?php

namespace App\Modules\CandidateGenerator\Infrastructure;

use App\Modules\CandidateGenerator\Application\IndependentLegCriteriaFactory;
use App\Modules\CandidateGenerator\Contracts\BudgetedCandidateLegSearch;
use App\Modules\CandidateGenerator\Contracts\CandidateLeg;
use App\Modules\CandidateGenerator\Contracts\LegSearchResult;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\SelfTransfer\Domain\RouteLegOffer;
use DateTimeZone;

final class ContextualDuffelCandidateLegSearch implements BudgetedCandidateLegSearch
{
    /** @var array<string, list<RouteLegOffer>> */
    private array $first = [];

    /** @var array<string, list<RouteLegOffer>> */
    private array $second = [];

    /**
     * @param array<string, array<string, mixed>> $hubPlaces
     * @param list<FlightOffer> $directOffers
     */
    public function __construct(
        private readonly FlightSearchCriteria $original,
        private readonly string $originId,
        private readonly string $destinationId,
        private readonly array $hubPlaces,
        private readonly array $directOffers,
        private readonly IndependentLegCriteriaFactory $criteria,
        private readonly DuffelIndependentLegAdapter $duffel,
    ) {}

    public function requestCount(CandidateLeg $leg): int
    {
        if ($leg->fromId === $this->originId && $leg->toId === $this->destinationId) {
            return 0;
        }
        if ($leg->toId === $this->destinationId) {
            return $this->singleArrivalDate($leg->fromId) === null ? 0 : 1;
        }

        return isset($this->hubPlaces[$leg->toId]) ? 1 : 0;
    }

    public function search(CandidateLeg $leg, string $providerId): LegSearchResult
    {
        if ($leg->fromId === $this->originId && $leg->toId === $this->destinationId) {
            return new LegSearchResult(true, 'existing-direct:'.count($this->directOffers));
        }
        if ($leg->fromId === $this->originId && isset($this->hubPlaces[$leg->toId])) {
            $offers = $this->duffel->search($this->criteria->firstLeg($this->original, $this->hubPlaces[$leg->toId]));
            $this->first[$leg->toId] = $this->duffel->normalize($leg->fromId, $leg->toId, $offers);

            return new LegSearchResult(true, 'duffel-leg:'.hash('sha256', $leg->fromId.'|'.$leg->toId));
        }
        if ($leg->toId === $this->destinationId && isset($this->hubPlaces[$leg->fromId])) {
            $date = $this->singleArrivalDate($leg->fromId);
            if ($date === null) {
                return new LegSearchResult(false);
            }
            $firstOffer = $this->first[$leg->fromId][0]->offer;
            $criteria = $this->criteria->secondLegFromArrival($this->original, $this->hubPlaces[$leg->fromId], $firstOffer);
            $offers = $this->duffel->search($criteria);
            $this->second[$leg->fromId] = $this->duffel->normalize($leg->fromId, $leg->toId, $offers);

            return new LegSearchResult(true, 'duffel-leg:'.hash('sha256', $leg->fromId.'|'.$leg->toId.'|'.$date));
        }

        return new LegSearchResult(false);
    }

    /** @return list<RouteLegOffer> */
    public function firstOffers(string $hubId): array
    {
        return $this->first[$hubId] ?? [];
    }

    /** @return list<RouteLegOffer> */
    public function secondOffers(string $hubId): array
    {
        return $this->second[$hubId] ?? [];
    }

    private function singleArrivalDate(string $hubId): ?string
    {
        $timezone = $this->hubPlaces[$hubId]['iana_timezone'] ?? null;
        if (! is_string($timezone) || ($offers = $this->first[$hubId] ?? []) === []) {
            return null;
        }
        $dates = [];
        foreach ($offers as $offer) {
            $last = $offer->offer->segments[array_key_last($offer->offer->segments)];
            $dates[] = $last->arrival->utcInstant->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
        }
        $dates = array_values(array_unique($dates));

        return count($dates) === 1 ? $dates[0] : null;
    }
}
