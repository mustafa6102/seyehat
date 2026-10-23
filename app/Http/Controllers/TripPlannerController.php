<?php

namespace App\Http\Controllers;

use App\Http\Support\AviationLocationSelection;
use App\Http\Support\PoiPresentation;
use App\Http\Support\SessionTripRequests;
use App\Http\Support\TripPresentation;
use App\Modules\Accommodation\Contracts\AccommodationSelectionReader;
use App\Modules\Api\Contracts\IncompleteTripRequest;
use App\Modules\Api\Contracts\InvalidTripRequest;
use App\Modules\Api\Contracts\OwnershipDecision;
use App\Modules\Api\Contracts\TravelDate;
use App\Modules\Api\Contracts\TripJourney;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightSelectionReader;
use App\Modules\ProviderAcl\Contracts\AviationLocationCatalog;
use App\Modules\ProviderAcl\Contracts\LocationCatalog;
use App\Modules\ProviderAcl\Contracts\LocationOption;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\TripBuilder\Application\TripPlanService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TripPlannerController extends Controller
{
    public function __construct(
        private readonly TripRequestService $tripRequests,
        private readonly LocationCatalog $locationCatalog,
        private readonly AviationLocationCatalog $aviationCatalog,
        private readonly AviationLocationSelection $selection,
        private readonly TripPresentation $presentation,
        private readonly SessionTripRequests $sessionTripRequests,
        private readonly WebPrincipalResolver $principals,
    ) {}

    public function index(): View
    {
        return $this->plannerView();
    }

    public function store(Request $request): RedirectResponse
    {
        // RULE-PLT-AUTH-001-04: a new TripRequest needs an authenticated owner. Until the browser OIDC login exists the web
        // principal is unavailable and nothing is created (no ownerless records, no default or session-derived owner).
        $owner = $this->principals->resolve($request);
        if ($owner === null) {
            return back()->withInput()->withErrors([
                'trip_request' => 'Seyahat talebi oluşturmak için giriş yapmanız gerekiyor. Giriş yaptıktan sonra formu yeniden gönderin.',
            ]);
        }
        // Flight locations (OD-022 v3.8): only a server-issued selection token yields kind/value/timezone and
        // aviation identity; browser-sent location fields are replaced by the token's server-normalized values.
        $selected = [];
        $resolved = [];
        foreach (['origin', 'destination'] as $prefix) {
            $location = $this->selection->open($request->input("{$prefix}_selection"));
            if ($location !== null) {
                $selected[$prefix] = $location;
                $request->merge([
                    "{$prefix}_kind" => $location->kind,
                    "{$prefix}_value" => $location->id,
                    "{$prefix}_timezone" => $location->ianaTimezone,
                ]);
            }
        }
        $selectionRule = fn (string $prefix): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($prefix, &$selected): void {
            if (! isset($selected[$prefix])) {
                $fail($prefix === 'origin' ? 'Seçilen başlangıç konumu doğrulanamadı; listeden yeniden seçin.' : 'Seçilen varış konumu doğrulanamadı; listeden yeniden seçin.');
            }
        };

        $validated = $request->validate([
            'origin_selection' => ['nullable', 'string', $selectionRule('origin')],
            'destination_selection' => ['nullable', 'string', $selectionRule('destination')],
            'origin_kind' => ['required', Rule::in(array_keys(TripPresentation::LOCATION_KINDS))],
            'origin_value' => ['required', 'string', $this->catalogEntryRule($request, 'origin', $resolved)],
            'origin_timezone' => ['required', 'timezone:all'],
            'destination_kind' => ['required', Rule::in(array_keys(TripPresentation::LOCATION_KINDS))],
            'destination_value' => ['required', 'string', $this->catalogEntryRule($request, 'destination', $resolved)],
            'destination_timezone' => ['required', 'timezone:all'],
            // TRIP-001 v3.9 (RULE-TRIP-001-04): the journey type is chosen explicitly, never inferred from dates.
            'journey_type' => ['required', Rule::in([TripJourney::ONE_WAY, TripJourney::ROUND_TRIP])],
            'outbound_date_mode' => ['required', Rule::in(['exact', 'flexible'])],
            'outbound_exact_date' => ['nullable', 'required_if:outbound_date_mode,exact', 'date_format:Y-m-d'],
            'outbound_start_date' => ['nullable', 'required_if:outbound_date_mode,flexible', 'date_format:Y-m-d'],
            'outbound_end_date' => ['nullable', 'required_if:outbound_date_mode,flexible', 'date_format:Y-m-d'],
            'return_date_mode' => ['nullable', 'prohibited_unless:journey_type,'.TripJourney::ROUND_TRIP, 'required_if:journey_type,'.TripJourney::ROUND_TRIP, Rule::in(['exact', 'flexible'])],
            'return_exact_date' => ['nullable', 'prohibited_unless:journey_type,'.TripJourney::ROUND_TRIP, 'required_if:return_date_mode,exact', 'date_format:Y-m-d'],
            'return_start_date' => ['nullable', 'prohibited_unless:journey_type,'.TripJourney::ROUND_TRIP, 'required_if:return_date_mode,flexible', 'date_format:Y-m-d'],
            'return_end_date' => ['nullable', 'prohibited_unless:journey_type,'.TripJourney::ROUND_TRIP, 'required_if:return_date_mode,flexible', 'date_format:Y-m-d'],
            'traveler_count' => ['required', 'integer', 'min:1'],
            'adults' => ['sometimes', 'required_with:children,infants', 'integer', 'min:0'],
            'children' => ['sometimes', 'required_with:adults,infants', 'integer', 'min:0'],
            'infants' => ['sometimes', 'required_with:adults,children', 'integer', 'min:0'],
            'pace_preference' => ['nullable', 'string'],
            // v3.14 accommodation input: explicit user value; absent stays null and is never inferred.
            'room_count' => ['nullable', 'integer', 'min:1'],
        ], [
            'room_count.integer' => 'Oda sayısı tam sayı olmalıdır.',
            'room_count.min' => 'En az bir oda seçin.',
            'origin_kind.required' => 'Başlangıç konumunu listeden seçin.',
            'origin_kind.in' => 'Seçilen başlangıç konumu desteklenmiyor; listeden yeniden seçin.',
            'origin_value.required' => 'Başlangıç konumunu listeden seçin.',
            'origin_timezone.required' => 'Başlangıç konumunu listeden seçin.',
            'origin_timezone.timezone' => 'Seçilen başlangıç konumu doğrulanamadı; listeden yeniden seçin.',
            'destination_kind.required' => 'Varış konumunu listeden seçin.',
            'destination_kind.in' => 'Seçilen varış konumu desteklenmiyor; listeden yeniden seçin.',
            'destination_value.required' => 'Varış konumunu listeden seçin.',
            'destination_timezone.required' => 'Varış konumunu listeden seçin.',
            'destination_timezone.timezone' => 'Seçilen varış konumu doğrulanamadı; listeden yeniden seçin.',
            'journey_type.required' => 'Yolculuk tipini seçin: tek yön veya gidiş-dönüş.',
            'journey_type.in' => 'Geçerli bir yolculuk tipi seçin.',
            'outbound_date_mode.required' => 'Gidiş için tarih seçimi yapın.',
            'outbound_date_mode.in' => 'Gidiş için geçerli bir tarih seçimi yapın.',
            'outbound_exact_date.required_if' => 'Gidiş tarihini girin.',
            'outbound_exact_date.date_format' => 'Gidiş tarihini geçerli biçimde girin.',
            'outbound_start_date.required_if' => 'Esnek gidiş için başlangıç tarihini girin.',
            'outbound_start_date.date_format' => 'Gidiş başlangıç tarihini geçerli biçimde girin.',
            'outbound_end_date.required_if' => 'Esnek gidiş için bitiş tarihini girin.',
            'outbound_end_date.date_format' => 'Gidiş bitiş tarihini geçerli biçimde girin.',
            'return_date_mode.required_if' => 'Gidiş-dönüş yolculukta dönüş tarihi zorunludur.',
            'return_date_mode.in' => 'Dönüş için geçerli bir tarih seçimi yapın.',
            'return_exact_date.required_if' => 'Dönüş tarihini girin.',
            'return_exact_date.date_format' => 'Dönüş tarihini geçerli biçimde girin.',
            'return_start_date.required_if' => 'Esnek dönüş için başlangıç tarihini girin.',
            'return_start_date.date_format' => 'Dönüş başlangıç tarihini geçerli biçimde girin.',
            'return_end_date.required_if' => 'Esnek dönüş için bitiş tarihini girin.',
            'return_end_date.date_format' => 'Dönüş bitiş tarihini geçerli biçimde girin.',
            '*.prohibited_unless' => 'Tek yön yolculukta dönüş tarihi gönderilemez.',
            'traveler_count.required' => 'Yolcu sayısını girin.',
            'traveler_count.integer' => 'Yolcu sayısı tam sayı olmalıdır.',
            'traveler_count.min' => 'En az bir yolcu seçin.',
            '*.required_with' => 'Yetişkin, çocuk ve bebek sayılarını birlikte girin.',
            'adults.integer' => 'Yetişkin sayısı tam sayı olmalıdır.',
            'adults.min' => 'Yetişkin sayısı negatif olamaz.',
            'children.integer' => 'Çocuk sayısı tam sayı olmalıdır.',
            'children.min' => 'Çocuk sayısı negatif olamaz.',
            'infants.integer' => 'Bebek sayısı tam sayı olmalıdır.',
            'infants.min' => 'Bebek sayısı negatif olamaz.',
            'origin_value.string' => 'Başlangıç konumunu listeden seçin.',
            'destination_value.string' => 'Varış konumunu listeden seçin.',
            'origin_selection.string' => 'Seçilen başlangıç konumu doğrulanamadı; listeden yeniden seçin.',
            'destination_selection.string' => 'Seçilen varış konumu doğrulanamadı; listeden yeniden seçin.',
            'pace_preference.string' => 'Tempo tercihini metin olarak girin.',
        ]);

        $input = $this->toContract($validated, $selected + $resolved);
        if (isset($validated['adults'], $validated['children'], $validated['infants']) && $input['traveler_count'] < 1) {
            throw ValidationException::withMessages(['adults' => 'En az bir yolcu seçin.']);
        }
        $journeyErrors = $this->journeyErrors($input);
        if ($journeyErrors !== []) {
            throw ValidationException::withMessages($journeyErrors);
        }

        try {
            $created = $this->tripRequests->create($input, $owner);
        } catch (InvalidTripRequest) {
            throw ValidationException::withMessages(['journey_type' => 'Yolculuk tipi ve tarih bilgileri geçerli değil; kontrol edip yeniden deneyin.']);
        } catch (IncompleteTripRequest $exception) {
            return back()->withInput()->withErrors([
                'trip_request' => $exception->getMessage(),
            ]);
        }
        $this->sessionTripRequests->remember($request->session(), $created->id);

        return redirect()->route('trip-planner.show', ['tripRequestId' => $created->id]);
    }

    /**
     * A GeoNames catalog identity is re-resolved on the server; browser-sent kind and timezone must match the
     * catalog entry (TRIP-001 FR-TRIP-001-02). Aviation selections are verified by their own token instead.
     */
    private function catalogEntryRule(Request $request, string $prefix, array &$resolved): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($request, $prefix, &$resolved): void {
            if (! is_string($value) || ! str_starts_with($value, 'geonames:') || $request->filled("{$prefix}_selection")) {
                return;
            }
            $place = $prefix === 'origin' ? 'başlangıç' : 'varış';
            if (! $this->locationCatalog->isAvailable()) {
                $fail("Konum kataloğu şu anda kullanılamadığından seçilen {$place} konumu doğrulanamadı.");

                return;
            }
            try {
                $entry = $this->locationCatalog->resolve($value);
            } catch (ProviderFailure) {
                $fail("Seçilen {$place} konumu şu anda doğrulanamıyor; lütfen biraz sonra yeniden deneyin.");

                return;
            }
            if ($entry === null || $entry->kind !== $request->input("{$prefix}_kind") || $entry->ianaTimezone !== $request->input("{$prefix}_timezone")) {
                $fail("Seçilen {$place} konumu doğrulanamadı; listeden yeniden seçin.");

                return;
            }
            $resolved[$prefix] = $entry;
        };
    }

    public function show(string $tripRequestId, FlightSearchExecutor $flightSearch): View
    {
        $tripRequest = $this->tripRequests->get($tripRequestId);
        abort_if($tripRequest === null, 404);

        return view('trip-summary', [
            'tripRequestId' => $tripRequestId,
            'tripRequest' => $tripRequest,
            'origin' => $this->presentation->place($tripRequest->originPlace),
            'destination' => $this->presentation->place($tripRequest->destinationPlace),
            'journey' => $this->presentation->journey($tripRequest),
            'flightSearchAvailable' => $flightSearch->isAvailable(),
        ]);
    }

    /**
     * "Seyahat Planım" for a stored TripRequest: TripRequest facts plus the v3.13 flight selection. TRIP-BLD-001
     * Trip/ItineraryDay/PlanRevision persistence does not exist yet, so stay, days and other costs stay empty/unknown.
     */
    public function plan(Request $request, string $tripRequestId, FlightSelectionReader $flightSelections, AccommodationSelectionReader $accommodationSelections, TripRequestOwnership $ownership, TripPlanService $tripPlans): View
    {
        $tripRequest = $this->tripRequests->get($tripRequestId);
        abort_if($tripRequest === null, 404);

        $journey = $this->presentation->journey($tripRequest);
        $dates = match (true) {
            $journey['outbound'] !== null && $journey['return'] !== null => $journey['outbound']['text'].' → '.$journey['return']['text'],
            $journey['outbound'] !== null => $journey['outbound']['text'],
            $journey['legacy'] !== null => $journey['legacy']['text'],
            default => 'Tarih bilgisi yok',
        };
        $composition = $tripRequest->passengerComposition;
        $travelers = $composition === null
            ? "{$tripRequest->travelerCount} yolcu"
            : implode(', ', array_filter([
                $composition['adults'] > 0 ? "{$composition['adults']} yetişkin" : null,
                $composition['children'] > 0 ? "{$composition['children']} çocuk" : null,
                $composition['infants'] > 0 ? "{$composition['infants']} bebek" : null,
            ]));

        $route = $this->presentation->place($tripRequest->originPlace)['label'].' → '.$this->presentation->place($tripRequest->destinationPlace)['label'];
        [$flight, $flightCost, $flightCostNote] = $this->selectedFlight($flightSelections->find($tripRequestId), $route);
        [$stay, $stayCost, $stayCostNote] = $this->selectedStay($accommodationSelections->find($tripRequestId));

        return view('trip-plan', ['plan' => [
            'destination' => $this->presentation->place($tripRequest->destinationPlace)['label'],
            'dates' => $dates,
            'status' => ['SEARCHABLE' => 'Aramaya hazır', 'DRAFT' => 'Taslak'][$tripRequest->status] ?? $tripRequest->status,
            'travelers' => $travelers,
            'flight' => $flight,
            'stay' => $stay,
            'days' => [],
            'costs' => ['flight' => $flightCost, 'stay' => $stayCost, 'activities' => null, 'other' => null],
            'cost_notes' => ['flight' => $flightCostNote, 'stay' => $stayCostNote],
            // Known amounts are listed, never summed across currencies.
            'known_total' => implode(' + ', array_filter([$flightCost, $stayCost])) ?: null,
        ],
            // Identifier for the accommodation drawer's API calls; the same deployment polling interval as flight search.
            'tripRequestId' => $tripRequestId,
            'pollIntervalMs' => config('flight_search.poll_interval_ms'),
            // ADR-010 web channel: whether the signed-in principal owns this TripRequest (same guard as the plan API).
            'planAccess' => $access = $this->planAccess($request, $tripRequestId, $ownership),
            'poiPlan' => $access === 'OWNER' ? $this->poiPlan($tripPlans, $tripRequestId) : null,
        ]);
    }

    /**
     * The owner's unscheduled FLEXIBLE POI items of the CURRENT PlanRevision (v3.16), read through TripPlanService (never
     * creates a Trip). version is null before the first revision; it is the OD-027 precondition of the web forms.
     *
     * @return array{version: int|null, items: list<array{item_id: string, poi: array<string, mixed>}>, change: array<string, mixed>|null}
     */
    private function poiPlan(TripPlanService $tripPlans, string $tripRequestId): array
    {
        $revision = $tripPlans->read($tripRequestId)['body']['revision'];
        $items = array_map(fn (array $item): array => ['item_id' => $item['item_id'], 'poi' => PoiPresentation::describe($item['poi_snapshot'])], $revision['items'] ?? []);

        return [
            'version' => $revision['version'] ?? null,
            'items' => $items,
            'change' => $revision === null ? null : $this->revisionChange($revision, $items),
        ];
    }

    /**
     * FR-TRIP-BLD-001-02 / AC-TRIP-BLD-001-02: the CURRENT revision's stored diff (SCH-PlanRevision.change) as written, never
     * recomputed. Added items are named from this revision; removed items exist only in the base revision, so they are
     * counted, not named. created_at is the stored UTC instant.
     *
     * @param  array<string, mixed>  $revision
     * @param  list<array{item_id: string, poi: array<string, mixed>}>  $items
     * @return array{version: int, base_version: int|null, created_at: string, added: list<string>, removed_count: int, reason: string}
     */
    private function revisionChange(array $revision, array $items): array
    {
        $names = array_column(array_map(fn (array $item): array => ['id' => $item['item_id'], 'name' => $item['poi']['name']], $items), 'name', 'id');
        $reason = $revision['change']['reason_code'];

        return [
            'version' => $revision['version'],
            'base_version' => $revision['change']['base_version'],
            'created_at' => Carbon::parse($revision['created_at'])->utc()->format('d.m.Y H:i').' UTC',
            'added' => array_values(array_map(fn (string $id): string => $names[$id] ?? 'Adı bilinmeyen yer', $revision['change']['added_item_ids'])),
            'removed_count' => count($revision['change']['removed_item_ids']),
            // v3.16: RC-DECISION-TRANSITION-APPLIED is the user add/remove reason; any other stored code is shown unchanged.
            'reason' => $reason === TripPlanService::REASON_CODE ? 'Kullanıcı değişikliği' : $reason,
        ];
    }

    /** OWNER, NOT_OWNER (other principal or ownerless legacy record) or SIGNED_OUT; viewing itself is unchanged. */
    private function planAccess(Request $request, string $tripRequestId, TripRequestOwnership $ownership): string
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return 'SIGNED_OUT';
        }

        return $ownership->check($principal, $tripRequestId) === OwnershipDecision::ALLOWED ? 'OWNER' : 'NOT_OWNER';
    }

    /**
     * Display of a v3.14 accommodation selection. Only a CURRENT selection with a verified (complete) price yields a cost;
     * a stored price of a RECONFIRMATION_REQUIRED or EXPIRED selection is never shown as current, unknown is never 0, and
     * unverified check-in or cancellation information is labelled as such.
     *
     * @param  array<string, mixed>|null  $selection
     * @return array{0: array<string, string>|null, 1: string|null, 2: string|null}
     */
    private function selectedStay(?array $selection): array
    {
        if ($selection === null) {
            return [null, null, null];
        }
        $offer = $selection['offer'];
        $date = fn (string $value): string => Carbon::parse($value)->locale('tr')->translatedFormat('j F');
        $detail = implode(' · ', array_filter([
            $date($offer['check_in_date']).' – '.$date($offer['check_out_date']),
            $offer['room_description'] ?? null,
            isset($offer['check_in']['from_time']) ? 'Giriş '.$offer['check_in']['from_time'].' sonrası (doğrulanmadı)' : null,
        ]));
        $cancellation = $offer['cancellation'] ?? [];
        $cancellationText = match ($cancellation['policy'] ?? 'UNKNOWN') {
            'REFUNDABLE' => isset($cancellation['free_cancellation_until'])
                ? 'Ücretsiz iptal: '.Carbon::parse($cancellation['free_cancellation_until'])->locale('tr')->translatedFormat('j F Y, H:i').' (UTC) öncesine kadar'
                : 'İade edilebilir',
            'PARTIALLY_REFUNDABLE' => 'Kısmen iade edilebilir',
            'NON_REFUNDABLE' => 'İade edilemez',
            default => 'İptal koşulu bilinmiyor',
        };
        if (($cancellation['verification_status'] ?? 'UNVERIFIED') !== 'VERIFIED' && ($cancellation['policy'] ?? 'UNKNOWN') !== 'UNKNOWN') {
            $cancellationText .= ' (doğrulanmadı)';
        }
        $verified = 'Son doğrulama: '.Carbon::parse($selection['verified_at'])->locale('tr')->translatedFormat('j F Y, H:i').' (UTC)';
        $price = $selection['verified_price'] === null ? null : $this->formatMoney($selection['verified_price']);
        $base = ['name' => (string) $offer['property_name'], 'detail' => $detail, 'cancellation' => $cancellationText];

        return match ($selection['status']) {
            'CURRENT' => [
                $base + ['verified' => $verified, 'state' => 'current', 'state_label' => 'Doğrulandı'],
                $price,
                $price === null ? 'Toplam fiyat kesinleşmedi (tesiste ödenecek ücretler bilinmiyor)' : null,
            ],
            'RECONFIRMATION_REQUIRED' => [
                $base + ['verified' => 'Fiyat veya koşullar değişti; konaklamanın yeniden onaylanması gerekiyor.', 'state' => 'reconfirm', 'state_label' => 'Yeniden onay gerekiyor'],
                null,
                'Yeniden onay gerekiyor',
            ],
            default => [
                $base + ['verified' => 'Seçilen konaklama teklifinin süresi doldu; yeni bir arama yapın.', 'state' => 'expired', 'state_label' => 'Süresi doldu'],
                null,
                'Teklifin süresi doldu',
            ],
        };
    }

    /**
     * Display of a v3.13 selection. Only a CURRENT selection with a known verified price yields a cost; a stored price
     * of a RECONFIRMATION_REQUIRED or EXPIRED selection is never presented as current, and an unknown price is never 0.
     *
     * @param  array<string, mixed>|null  $selection
     * @return array{0: array<string, string>|null, 1: string|null, 2: string|null}
     */
    private function selectedFlight(?array $selection, string $route): array
    {
        if ($selection === null) {
            return [null, null, null];
        }
        $segments = $selection['offer']['segments'] ?? [];
        $first = $segments[0] ?? [];
        $time = fn (array $point): ?string => isset($point['local_datetime'])
            ? Carbon::parse($point['local_datetime'])->locale('tr')->translatedFormat('j F, H:i')
            : null;
        $stops = max(0, count($segments) - 1);
        $detail = implode(' · ', array_filter([
            $time($first['departure'] ?? []),
            isset($first['operating_carrier'], $first['operating_flight_number']) ? $first['operating_carrier'].' '.$first['operating_flight_number'] : null,
            $stops === 0 ? 'Aktarmasız' : "{$stops} aktarma",
        ]));
        $verifiedAt = 'Son doğrulama: '.Carbon::parse($selection['verified_at'])->locale('tr')->translatedFormat('j F Y, H:i').' (UTC)';
        $price = $selection['verified_price'] === null ? null : $this->formatMoney($selection['verified_price']);

        return match ($selection['status']) {
            'CURRENT' => [
                ['route' => $route, 'detail' => $detail, 'verified' => $verifiedAt, 'state' => 'current', 'state_label' => 'Doğrulandı'],
                $price,
                $price === null ? 'Toplam fiyat doğrulanamadı' : null,
            ],
            'RECONFIRMATION_REQUIRED' => [
                ['route' => $route, 'detail' => $detail, 'verified' => 'Fiyat veya koşullar değişti; uçuşun yeniden onaylanması gerekiyor.', 'state' => 'reconfirm', 'state_label' => 'Yeniden onay gerekiyor'],
                null,
                'Yeniden onay gerekiyor',
            ],
            default => [
                ['route' => $route, 'detail' => $detail, 'verified' => 'Seçilen uçuş teklifinin süresi doldu; yeni bir arama yapın.', 'state' => 'expired', 'state_label' => 'Süresi doldu'],
                null,
                'Teklifin süresi doldu',
            ],
        };
    }

    /** @param  array{minor_units: int, currency: string}  $money */
    private function formatMoney(array $money): string
    {
        $digits = in_array($money['currency'], ['JPY', 'KRW', 'CLP', 'ISK', 'VND'], true) ? 0 : 2;

        return number_format($money['minor_units'] / 10 ** $digits, $digits, ',', '.').' '.$money['currency'];
    }

    /**
     * RULE-TRIP-001-05 checks beyond single-field formats: reversed flexible ranges and a return that ends
     * before the outbound starts (latest(return_date) < earliest(outbound_date)).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function journeyErrors(array $input): array
    {
        $errors = [];
        $invalid = TripJourney::invalidFields($input['journey_type'], $input['outbound_date'], $input['return_date'] ?? null);
        if (in_array('outbound_date', $invalid, true)) {
            $errors['outbound_end_date'] = 'Gidiş için bitiş tarihi başlangıç tarihinden önce olamaz.';
        }
        if (in_array('return_date', $invalid, true)) {
            $errors += TravelDate::fromContract($input['return_date'] ?? null) === null
                ? ['return_end_date' => 'Dönüş için bitiş tarihi başlangıç tarihinden önce olamaz.']
                : ['return_date' => 'Dönüş tarihi, gidiş tarihinden önce olamaz.'];
        }

        return $errors;
    }

    /** @param  array<string, mixed>  $data */
    private function plannerView(array $data = []): View
    {
        return view('trip-planner', $data + [
            'locationKinds' => TripPresentation::LOCATION_KINDS,
            'locationCatalogAvailable' => $this->locationCatalog->isAvailable(),
            'aviationCatalogAvailable' => $this->aviationCatalog->isAvailable(),
            'locationSearchState' => $this->aviationCatalog->isAvailable() ? 'flight' : ($this->locationCatalog->isAvailable() ? 'general' : 'unavailable'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, LocationOption>  $selected  server-verified flight selections by prefix
     * @return array<string, mixed>
     */
    private function toContract(array $input, array $selected): array
    {
        // Server-verified catalog entry (flight selection token or re-resolved GeoNames entry): aviation identity and,
        // when the catalog supplies them, its coordinates (v3.14). Nothing here is taken from browser-sent fields.
        $aviation = fn (string $prefix): array => (isset($selected[$prefix]->aviationIdentity)
            ? ['aviation_identity' => $selected[$prefix]->aviationIdentity->toContract()]
            // Only the destination needs coordinates (accommodation search, OD-024); the origin stays unchanged.
            : []) + ($prefix === 'destination' && isset($selected[$prefix]) && $selected[$prefix]->latitude !== null && $selected[$prefix]->longitude !== null
            ? ['coordinates' => ['latitude' => $selected[$prefix]->latitude, 'longitude' => $selected[$prefix]->longitude]]
            : []);

        // Canonical SCH-TravelDate values; only the chosen journey's direction(s) and date mode are used.
        $travelDate = fn (string $direction): array => $input["{$direction}_date_mode"] === 'exact'
            ? ['type' => TravelDate::EXACT, 'date' => $input["{$direction}_exact_date"]]
            : ['type' => TravelDate::FLEXIBLE, 'start_date' => $input["{$direction}_start_date"], 'end_date' => $input["{$direction}_end_date"]];
        $journey = ['journey_type' => $input['journey_type'], 'outbound_date' => $travelDate('outbound')];
        if ($input['journey_type'] === TripJourney::ROUND_TRIP) {
            $journey['return_date'] = $travelDate('return');
        }

        $pacePreference = trim((string) ($input['pace_preference'] ?? ''));
        $composition = isset($input['adults'], $input['children'], $input['infants']) ? [
            'adults' => (int) $input['adults'],
            'children' => (int) $input['children'],
            'infants' => (int) $input['infants'],
        ] : null;
        $travelerCount = $composition === null ? (int) $input['traveler_count'] : array_sum($composition);

        return [
            'status' => 'DRAFT',
            'origin_place' => [
                'kind' => $input['origin_kind'],
                'value' => $input['origin_value'],
                'iana_timezone' => $input['origin_timezone'],
            ] + $aviation('origin'),
            'destination_place' => [
                'kind' => $input['destination_kind'],
                'value' => $input['destination_value'],
                'iana_timezone' => $input['destination_timezone'],
            ] + $aviation('destination'),
        ] + $journey + [
            'traveler_count' => $travelerCount,
            'passenger_composition' => $composition,
            'preferences' => $pacePreference === '' ? null : ['pace_preference' => $pacePreference],
        ] + (isset($input['room_count']) ? ['room_count' => (int) $input['room_count']] : []);
    }
}
