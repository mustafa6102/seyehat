<?php

namespace App\Modules\Api\Application;

use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\IncompleteTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Domain\TripRequest\TripRequestRepository;
use App\Modules\Authorization\Contracts\PrincipalRef;

final readonly class TripRequestApplicationService implements TripRequestService
{
    public function __construct(private TripRequestRepository $tripRequests) {}

    public function create(array $input, PrincipalRef $owner): CreatedTripRequest
    {
        // The owner comes only from the authenticated principal, never from $input (RULE-PLT-AUTH-001-04).
        $tripRequest = TripRequest::draft($input)->normalize()->ownedBy($owner);
        $stored = $this->tripRequests->save($tripRequest);
        $missingFields = $stored->missingRequiredFields();

        if ($missingFields !== []) {
            throw new IncompleteTripRequest($missingFields);
        }

        return new CreatedTripRequest($stored->id, $stored->toView());
    }

    public function get(string $id): ?TripRequestView
    {
        $tripRequest = $this->tripRequests->find($id);

        return $tripRequest?->toView();
    }
}
