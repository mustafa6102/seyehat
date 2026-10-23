<?php

namespace App\Modules\Api\Contracts;

final readonly class CreatedTripRequest
{
    public function __construct(
        public string $id,
        public TripRequestView $tripRequest,
    ) {}
}
