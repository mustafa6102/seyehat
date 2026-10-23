<?php

namespace App\Http\Controllers;

use App\Http\Support\SessionTripRequests;
use App\Http\Support\TripPresentation;
use App\Modules\Api\Contracts\TripRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** "Taleplerim": only the TripRequests created in the current browser session (see SessionTripRequests). */
final class MyTripRequestsController extends Controller
{
    public function __construct(
        private readonly TripRequestService $tripRequests,
        private readonly SessionTripRequests $sessionTripRequests,
        private readonly TripPresentation $presentation,
    ) {}

    public function index(Request $request): View
    {
        $cards = [];
        $stale = [];
        foreach ($this->sessionTripRequests->newestFirst($request->session()) as $id) {
            $tripRequest = $this->tripRequests->get($id);
            if ($tripRequest === null) {
                $stale[] = $id;

                continue;
            }
            $cards[] = [
                'id' => $id,
                'origin' => $this->presentation->place($tripRequest->originPlace)['label'],
                'destination' => $this->presentation->place($tripRequest->destinationPlace)['label'],
                'journey' => $this->presentation->journey($tripRequest),
                'travelerCount' => $tripRequest->travelerCount,
                'searchable' => $tripRequest->status === 'SEARCHABLE',
            ];
        }

        $this->sessionTripRequests->forget($request->session(), $stale);

        return view('my-trip-requests', ['cards' => $cards]);
    }
}
