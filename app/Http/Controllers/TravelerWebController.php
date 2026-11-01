<?php

namespace App\Http\Controllers;

use App\Http\Support\PrivacyNotices;
use App\Http\Support\TripPresentation;
use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Authorization\Contracts\AccessDenied;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use App\Modules\Traveler\Contracts\BasicTraveler;
use App\Modules\Traveler\Contracts\BasicTravelerActivation;
use App\Modules\Traveler\Contracts\BasicTravelerRejected;
use App\Modules\Traveler\Contracts\BasicTravelerRoster;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;
use App\Modules\Traveler\Contracts\TravelerSubjectRequests;
use App\Modules\Traveler\Contracts\TripTravelerEnrollment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * TRV-001 owner screens. A slot is a position derived from the owned TripRequest's passenger counts, not a Traveler
 * record: the legacy traveler routes stay bearer + fail-closed 403 (OD-P8-TRV-SCOPE). Basic Traveler rows are shown and
 * added only behind BasicTravelerActivation, through TripTravelerEnrollment; no PII is read or written here.
 */
final class TravelerWebController extends Controller
{
    private const FIELDS = ['Yaş kategorisi', 'Vatandaşlık', 'Seyahat belgesi', 'Vize', 'Oturum izni'];

    public function __construct(
        private readonly TripRequestService $tripRequests,
        private readonly TripRequestOwnership $ownership,
        private readonly WebPrincipalResolver $principals,
        private readonly TripPresentation $presentation,
        private readonly PrivacyNotices $notices,
        private readonly TripTravelerEnrollment $enrollment,
        private readonly BasicTravelerActivation $activation,
        private readonly TravelerSubjectRequests $subjectRequests,
    ) {}

    public function index(Request $request, string $tripRequestId): View
    {
        $tripRequest = $this->ownedTripRequest($request, $tripRequestId);
        $roster = $this->roster($request, $tripRequestId);
        $slots = $this->slots($tripRequest, $roster);

        return view('travelers.index', $this->context($tripRequest, $tripRequestId) + [
            'slots' => $slots,
            'typeCounts' => array_count_values(array_column($slots, 'typeLabel')),
            'roster' => $roster,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /** Web channel of the Basic Traveler create: the browser supplies only the per-page key; the category is fixed here. */
    public function store(Request $request, string $tripRequestId): RedirectResponse
    {
        $this->ownedTripRequest($request, $tripRequestId);
        $back = redirect()->route('traveler-screens.index', ['tripRequestId' => $tripRequestId]);

        try {
            $this->enrollment->enroll($this->principal($request), $tripRequestId, $request->input('idempotency_key'), ['age_category' => 'ADULT']);
        } catch (BasicTravelerRejected $exception) {
            abort_if($exception->httpStatus === 404, 404);

            return $back->with('traveler_error', $exception->getMessage());
        } catch (TravelerStorageClosed $exception) {
            return $back->with('traveler_error', $exception->getMessage());
        } catch (AccessDenied) {
            abort(403);
        }

        return $back->with('traveler_status', 'Yetişkin yolcu eklendi.');
    }

    public function record(Request $request, string $tripRequestId, string $travelerId): View
    {
        $tripRequest = $this->ownedTripRequest($request, $tripRequestId);

        try {
            $traveler = $this->enrollment->find($this->principal($request), $tripRequestId, $travelerId);
            $openRequests = $this->subjectRequests->openTypes($this->principal($request), $tripRequestId, $travelerId);
            $roster = $this->enrollment->roster($this->principal($request), $tripRequestId);
        } catch (BasicTravelerRejected) {
            abort(404);
        } catch (AccessDenied) {
            abort(403);
        }

        $position = array_search($traveler->id, array_map(fn (BasicTraveler $record): string => $record->id, $roster->travelers), true);

        return view('travelers.record', $this->context($tripRequest, $tripRequestId) + [
            'traveler' => $traveler,
            'ordinal' => $position === false ? null : $position + 1,
            'openRequests' => $openRequests,
        ]);
    }

    /** Files a deletion or information request; an open one of the same type is kept, never duplicated. */
    public function subjectRequest(Request $request, string $tripRequestId, string $travelerId): RedirectResponse
    {
        $this->ownedTripRequest($request, $tripRequestId);
        $type = $request->input('request_type');
        abort_unless(is_string($type), 404);

        try {
            $this->subjectRequests->file($this->principal($request), $tripRequestId, $travelerId, $type);
        } catch (BasicTravelerRejected) {
            abort(404);
        } catch (AccessDenied) {
            abort(403);
        }

        return redirect()->route('traveler-screens.record', ['tripRequestId' => $tripRequestId, 'travelerId' => $travelerId])
            ->with('traveler_status', 'Başvurunuz alındı.');
    }

    public function create(Request $request, string $tripRequestId): View
    {
        $tripRequest = $this->ownedTripRequest($request, $tripRequestId);

        return view('travelers.form', $this->context($tripRequest, $tripRequestId) + [
            'slots' => $this->slots($tripRequest),
            'slot' => null,
        ]);
    }

    public function show(Request $request, string $tripRequestId, int $slot): View
    {
        return view('travelers.show', $this->slotContext($request, $tripRequestId, $slot) + ['fields' => self::FIELDS]);
    }

    public function edit(Request $request, string $tripRequestId, int $slot): View
    {
        return view('travelers.form', $this->slotContext($request, $tripRequestId, $slot) + ['slots' => []]);
    }

    public function documents(Request $request, string $tripRequestId, int $slot): View
    {
        return view('travelers.documents', $this->slotContext($request, $tripRequestId, $slot));
    }

    /** Same order as the plan history page: 401 before any lookup, then 404, then 403 (RULE-PLT-AUTH-001-04..06). */
    private function ownedTripRequest(Request $request, string $tripRequestId): TripRequestView
    {
        $principal = $this->principals->resolve($request);
        abort_if($principal === null, 401);
        $access = $this->ownership->check($principal, $tripRequestId);
        abort_if($access === OwnershipDecision::NOT_FOUND, 404);
        abort_unless($access === OwnershipDecision::ALLOWED, 403);

        $tripRequest = $this->tripRequests->get($tripRequestId);
        abort_if($tripRequest === null, 404);

        return $tripRequest;
    }

    private function principal(Request $request): PrincipalRef
    {
        return $this->principals->resolve($request) ?? abort(401);
    }

    private function roster(Request $request, string $tripRequestId): ?BasicTravelerRoster
    {
        return $this->activation->isActive() ? $this->enrollment->roster($this->principal($request), $tripRequestId) : null;
    }

    /** @return array<string, mixed> */
    private function slotContext(Request $request, string $tripRequestId, int $number): array
    {
        $tripRequest = $this->ownedTripRequest($request, $tripRequestId);
        $slot = $this->slots($tripRequest, $this->roster($request, $tripRequestId))[$number - 1] ?? null;
        abort_if($slot === null, 404);

        return $this->context($tripRequest, $tripRequestId) + ['slot' => $slot];
    }

    /** @return array<string, mixed> */
    private function context(TripRequestView $tripRequest, string $tripRequestId): array
    {
        return [
            'tripRequestId' => $tripRequestId,
            'origin' => $this->presentation->place($tripRequest->originPlace)['label'],
            'destination' => $this->presentation->place($tripRequest->destinationPlace)['label'],
            'notice' => $this->notices->travelerProfile(),
            'basicActive' => $this->activation->isActive(),
        ];
    }

    /**
     * Adults come first, so the Nth adult slot shows the Nth record of the roster. Without a passenger composition a slot has
     * no known type and no record is matched to it.
     *
     * @return list<array{number: int, label: string, typeLabel: string, minor: bool, traveler: ?BasicTraveler}>
     */
    private function slots(TripRequestView $tripRequest, ?BasicTravelerRoster $roster = null): array
    {
        $composition = $tripRequest->passengerComposition;
        $types = $composition === null
            ? array_fill(0, $tripRequest->travelerCount, ['Yolcu tipi belirtilmedi', false])
            : [
                ...array_fill(0, $composition['adults'], ['Yetişkin', false]),
                ...array_fill(0, $composition['children'], ['Çocuk', true]),
                ...array_fill(0, $composition['infants'], ['Bebek', true]),
            ];

        $records = array_slice($roster?->travelers ?? [], 0, $composition['adults'] ?? 0);

        return array_map(fn (int $index, array $type): array => [
            'number' => $index + 1,
            'label' => 'Yolcu '.($index + 1),
            'typeLabel' => $type[0],
            'minor' => $type[1],
            'traveler' => $records[$index] ?? null,
        ], array_keys($types), $types);
    }
}
