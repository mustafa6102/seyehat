<?php

namespace App\Http\Controllers;

use App\Http\Support\TripPresentation;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\UnsupportedFlightSearchDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Server-rendered shells for the flight search, results and offer detail flow; data comes from the v1 API. */
final class FlightPagesController extends Controller
{
    public function __construct(
        private readonly FlightSearchExecutor $flightSearch,
        private readonly TripPresentation $presentation,
    ) {}

    public function search(string $tripRequestId, TripRequestService $tripRequests): View
    {
        $tripRequest = $tripRequests->get($tripRequestId);
        abort_if($tripRequest === null, 404);
        $criteria = null;
        $dateBlocker = null;
        if ($tripRequest->status === 'SEARCHABLE') {
            try {
                $criteria = FlightSearchCriteria::fromSearchableTripRequest($tripRequestId, $tripRequest);
            } catch (UnsupportedFlightSearchDate $unsupportedDate) {
                $dateBlocker = $unsupportedDate->getMessage();
            }
        }

        return view('flight-search', [
            'tripRequestId' => $tripRequestId,
            'origin' => $this->presentation->place($tripRequest->originPlace),
            'destination' => $this->presentation->place($tripRequest->destinationPlace),
            'journey' => $this->presentation->journey($tripRequest),
            'travelerCount' => $tripRequest->travelerCount,
            'searchable' => $tripRequest->status === 'SEARCHABLE',
            'locationBlocker' => $criteria?->locationBlockerTr(),
            'journeyBlocker' => $criteria?->journeyBlockerTr(),
            'dateBlocker' => $dateBlocker,
            'available' => $this->flightSearch->isAvailable(),
            'pollIntervalMs' => config('flight_search.poll_interval_ms'),
        ]);
    }

    public function offer(string $offerId, Request $request): View
    {
        // ?talep= is only an identifier from the results page; the selection API verifies the offer belongs to it.
        $tripRequestId = $request->query('talep');

        return view('offer', [
            'offerId' => $offerId,
            'tripRequestId' => is_string($tripRequestId) && ctype_digit($tripRequestId) ? $tripRequestId : null,
        ]);
    }
}
