<?php

namespace App\Http\Controllers;

use App\Http\Support\SessionTripRequests;
use App\Http\Support\TripPresentation;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** "Taleplerim": authenticated owners see their persisted requests; signed-out users retain the session fallback. */
final class MyTripRequestsController extends Controller
{
    public function __construct(
        private readonly TripRequestService $tripRequests,
        private readonly SessionTripRequests $sessionTripRequests,
        private readonly TripPresentation $presentation,
        private readonly WebPrincipalResolver $principals,
    ) {}

    public function index(Request $request): View
    {
        $cards = [];
        $stale = [];
        $principal = $this->principals->resolve($request);
        $records = $principal === null
            ? array_map(fn (string $id): array => ['id' => $id, 'trip_request' => $this->tripRequests->get($id)], $this->sessionTripRequests->newestFirst($request->session()))
            : $this->tripRequests->ownedBy($principal);

        foreach ($records as $record) {
            $id = $record['id'];
            $tripRequest = $record['trip_request'];
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

        return view('my-trip-requests', ['cards' => $cards, 'accountScoped' => $principal !== null]);
    }
}
