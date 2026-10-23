<?php

namespace App\Modules\FlightSearch\Domain;

final readonly class OfferChangeSet
{
    public bool $criticalChange;

    public function __construct(
        public bool $priceChanged,
        public bool $fareConditionsChanged,
        public bool $baggageChanged,
    ) {
        $this->criticalChange = $priceChanged || $fareConditionsChanged || $baggageChanged;
    }

    /** @return array{price_changed: bool, fare_conditions_changed: bool, baggage_changed: bool, critical_change: bool} */
    public function toContract(): array
    {
        return [
            'price_changed' => $this->priceChanged,
            'fare_conditions_changed' => $this->fareConditionsChanged,
            'baggage_changed' => $this->baggageChanged,
            'critical_change' => $this->criticalChange,
        ];
    }
}
