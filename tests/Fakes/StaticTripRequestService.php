<?php

namespace Tests\Fakes;

use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Authorization\Contracts\PrincipalRef;
use RuntimeException;

/**
 * Fixed TripRequests for flight search tests: trip-1/trip-2 SEARCHABLE round trips (v3.9), trip-draft DRAFT,
 * trip-legacy a SEARCHABLE pre-v3.9 record that only has a legacy date_window.
 */
final class StaticTripRequestService implements TripRequestService
{
    public function create(array $input, PrincipalRef $owner): CreatedTripRequest
    {
        throw new RuntimeException('Not used.');
    }

    public function get(string $id): ?TripRequestView
    {
        $status = ['trip-1' => 'SEARCHABLE', 'trip-2' => 'SEARCHABLE', 'trip-draft' => 'DRAFT', 'trip-legacy' => 'SEARCHABLE'][$id] ?? null;
        $legacy = $id === 'trip-legacy';

        return $status === null ? null : new TripRequestView(
            status: $status,
            originPlace: ['kind' => 'city', 'value' => 'geonames:745044', 'iana_timezone' => 'Europe/Istanbul'],
            destinationPlace: ['kind' => 'airport', 'value' => 'geonames:6299784', 'iana_timezone' => 'Europe/Rome'],
            dateWindow: $legacy ? ['start_date' => '2026-10-15', 'end_date' => '2026-10-20'] : null,
            travelerCount: 2,
            preferences: ['pace_preference' => 'dengeli'],
            journeyType: $legacy ? null : 'ROUND_TRIP',
            outboundDate: $legacy ? null : ['type' => 'FLEXIBLE', 'start_date' => '2026-10-15', 'end_date' => '2026-10-20'],
            returnDate: $legacy ? null : ['type' => 'EXACT', 'date' => '2026-10-27'],
        );
    }
}
