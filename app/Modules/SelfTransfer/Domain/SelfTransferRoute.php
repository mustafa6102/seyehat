<?php

namespace App\Modules\SelfTransfer\Domain;

use App\Modules\FlightSearch\Domain\Money;

final readonly class SelfTransferRoute
{
    /**
     * @param  list<RouteLegOffer>  $legs
     * @param  list<string>  $unknownCosts
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public string $id,
        public array $legs,
        public ?Money $knownTotal,
        public array $unknownCosts,
        public int $totalDurationSeconds,
        public int $connectionDurationSeconds,
        public string $riskLevel,
        public array $reasonCodes,
    ) {}

    public function deduplicationKey(): string
    {
        return implode('>', array_map(
            static fn (RouteLegOffer $leg): string => $leg->originId.'-'.$leg->destinationId.'@'.$leg->offer->providerOfferId,
            $this->legs,
        ));
    }

    /** @return array<string, mixed> */
    public function toPresentation(): array
    {
        return [
            'id' => $this->id,
            'type' => 'SELF_TRANSFER',
            'price' => $this->knownTotal?->toContract(),
            'price_completeness' => $this->unknownCosts === [] && $this->knownTotal !== null ? 'COMPLETE' : 'PARTIAL',
            'unknown_costs' => $this->unknownCosts,
            'total_duration_seconds' => $this->totalDurationSeconds,
            'connection_duration_seconds' => $this->connectionDurationSeconds,
            'risk_level' => $this->riskLevel,
            'reason_codes' => $this->reasonCodes,
            'segments' => array_map(static fn (RouteLegOffer $leg): array => [
                'origin_id' => $leg->originId,
                'destination_id' => $leg->destinationId,
                'provider_offer_id' => $leg->offer->providerOfferId,
                'offer' => $leg->offer->toContract(),
            ], $this->legs),
        ];
    }
}
