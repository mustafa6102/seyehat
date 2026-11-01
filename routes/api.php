<?php

use App\Modules\Accommodation\Infrastructure\Http\AccommodationOfferController;
use App\Modules\Accommodation\Infrastructure\Http\AccommodationSearchController;
use App\Modules\Accommodation\Infrastructure\Http\AccommodationSelectionController;
use App\Modules\Api\Infrastructure\Http\TripRequestController;
use App\Modules\FlightSearch\Infrastructure\Http\FlightOfferController;
use App\Modules\FlightSearch\Infrastructure\Http\FlightRepriceController;
use App\Modules\FlightSearch\Infrastructure\Http\FlightSearchController;
use App\Modules\FlightSearch\Infrastructure\Http\FlightSelectionController;
use App\Modules\HowTo\Infrastructure\Http\HowGuideController;
use App\Modules\Opportunity\Infrastructure\Http\FlightOpportunityController;
use App\Modules\Poi\Infrastructure\Http\PoiController;
use App\Modules\Event\Infrastructure\Http\EventDiscoveryController;
use App\Modules\Poi\Infrastructure\Http\PoiDiscoveryController;
use App\Modules\Traveler\Infrastructure\Http\BasicTravelerController;
use App\Modules\Traveler\Infrastructure\Http\TravelerController;
use App\Modules\TripBuilder\Infrastructure\Http\TripPlanController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->noContent());
    Route::get('/trip-requests/{tripRequestId}/flight-opportunity', [FlightOpportunityController::class, 'show'])
        ->whereNumber('tripRequestId')->middleware('auth.bearer')->name('trip-requests.flight-opportunity');
    // v3.16 bearerAuth: OP-createTripRequest binds the authenticated principal as owner (RULE-PLT-AUTH-001-04).
    Route::post('/trip-requests', [TripRequestController::class, 'store'])->middleware('auth.bearer');
    // v3.16 PLT-AUTH-001 retrofit (od_impact ADR-010 blocked_pending_auth_task): every TripRequest-owned operation below
    // requires bearerAuth and the TripRequest owner; offer and standalone POI operations are not TripRequest-owned.
    Route::get('/trip-requests/{tripRequestId}', [TripRequestController::class, 'show'])
        ->middleware('auth.bearer')->name('trip-requests.show');
    // v3.13 TripRequest flight selection (OP-get/select/reconfirmTripRequestFlight...).
    Route::get('/trip-requests/{tripRequestId}/flight-selection', [FlightSelectionController::class, 'show'])
        ->whereNumber('tripRequestId')->middleware('auth.bearer')->name('trip-requests.flight-selection.show');
    Route::put('/trip-requests/{tripRequestId}/flight-selection', [FlightSelectionController::class, 'store'])
        ->whereNumber('tripRequestId')->middleware('auth.bearer')->name('trip-requests.flight-selection.store');
    Route::post('/trip-requests/{tripRequestId}/flight-selection/reconfirm', [FlightSelectionController::class, 'reconfirm'])
        ->whereNumber('tripRequestId')->middleware('auth.bearer')->name('trip-requests.flight-selection.reconfirm');
    Route::get('/offers/{offerId}', [FlightOfferController::class, 'show'])
        ->name('offers.show');
    Route::post('/offers/{offerId}/reprice', [FlightRepriceController::class, 'store'])
        ->name('offers.reprice');
    Route::post('/flight-search-jobs', [FlightSearchController::class, 'store'])
        ->middleware('auth.bearer')->name('flight-search-jobs.store');
    Route::get('/flight-search-jobs/{searchId}', [FlightSearchController::class, 'show'])
        ->middleware('auth.bearer')->name('flight-search-jobs.show');
    // v3.14 ACC-001 accommodation search (OP-startAccommodationSearch / OP-pollAccommodationSearch).
    Route::post('/accommodation-search-jobs', [AccommodationSearchController::class, 'store'])
        ->middleware('auth.bearer')->name('accommodation-search-jobs.store');
    Route::get('/accommodation-search-jobs/{searchId}', [AccommodationSearchController::class, 'show'])
        ->middleware('auth.bearer')->name('accommodation-search-jobs.show');
    Route::get('/accommodation-offers/{offerId}', [AccommodationOfferController::class, 'show'])
        ->name('accommodation-offers.show');
    Route::post('/accommodation-offers/{offerId}/revalidate', [AccommodationOfferController::class, 'revalidate'])
        ->name('accommodation-offers.revalidate');
    // v3.14 TripRequest accommodation selection (OP-get/select/reconfirmTripRequestAccommodation).
    Route::get('/trip-requests/{tripRequestId}/accommodation-selection', [AccommodationSelectionController::class, 'show'])
        ->whereNumber('tripRequestId')->middleware('auth.bearer')->name('trip-requests.accommodation-selection.show');
    Route::put('/trip-requests/{tripRequestId}/accommodation-selection', [AccommodationSelectionController::class, 'store'])
        ->whereNumber('tripRequestId')->middleware('auth.bearer')->name('trip-requests.accommodation-selection.store');
    Route::post('/trip-requests/{tripRequestId}/accommodation-selection/reconfirm', [AccommodationSelectionController::class, 'reconfirm'])
        ->whereNumber('tripRequestId')->middleware('auth.bearer')->name('trip-requests.accommodation-selection.reconfirm');
    // v3.15 POI-001 discovery/detail/verification (osm_overpass, development only; no add-to-plan).
    Route::post('/poi-discovery-jobs', [PoiDiscoveryController::class, 'store'])
        ->middleware('auth.bearer')->name('poi-discovery-jobs.store');
    Route::get('/poi-discovery-jobs/{searchId}', [PoiDiscoveryController::class, 'show'])
        ->middleware('auth.bearer')->name('poi-discovery-jobs.show');
    Route::get('/pois/{poiId}', [PoiController::class, 'show'])
        ->name('pois.show');
    Route::post('/pois/{poiId}/verify', [PoiController::class, 'verify'])
        ->name('pois.verify');
    // v3.16 TRIP-BLD-001 plan increment (OD-021, OD-027): bearerAuth + TripRequest owner; unscheduled FLEXIBLE POI items only.
    Route::get('/trip-requests/{tripRequestId}/plan', [TripPlanController::class, 'show'])
        ->middleware('auth.bearer')->name('trip-requests.plan.show');
    Route::post('/trip-requests/{tripRequestId}/plan/items', [TripPlanController::class, 'store'])
        ->middleware('auth.bearer')->name('trip-requests.plan.items.store');
    Route::delete('/trip-requests/{tripRequestId}/plan/items/{itemId}', [TripPlanController::class, 'destroy'])
        ->middleware('auth.bearer')->name('trip-requests.plan.items.destroy');
    // v3.18 DAY-001 (OD-029 A): manual day assignment / in-day order of a plan item; no time, duration or feasibility.
    Route::put('/trip-requests/{tripRequestId}/plan/items/{itemId}/day-assignment', [TripPlanController::class, 'assignDay'])
        ->middleware('auth.bearer')->name('trip-requests.plan.items.day-assignment');
    Route::put('/trip-requests/{tripRequestId}/plan/items/{itemId}/duration', [TripPlanController::class, 'setDuration'])
        ->middleware('auth.bearer')->name('trip-requests.plan.items.duration');
    // v3.22 EVT-001 event discovery (Ticketmaster, OD-EVT-001-TM) and EVENT/FIXED plan add.
    Route::post('/event-discovery-jobs', [EventDiscoveryController::class, 'store'])
        ->middleware('auth.bearer')->name('event-discovery-jobs.store');
    Route::get('/event-discovery-jobs/{searchId}', [EventDiscoveryController::class, 'show'])
        ->middleware('auth.bearer')->name('event-discovery-jobs.show');
    Route::post('/trip-requests/{tripRequestId}/plan/events', [TripPlanController::class, 'storeEvent'])
        ->middleware('auth.bearer')->name('trip-requests.plan.events.store');
    // v3.19 HOW-001: owner-only deterministic POI guide; GET is side-effect free, refresh reuses the source verifier.
    Route::get('/trip-requests/{tripRequestId}/plan/items/{itemId}/guide', [HowGuideController::class, 'show'])
        ->middleware('auth.bearer')->name('trip-requests.plan.items.guide.show');
    Route::post('/trip-requests/{tripRequestId}/plan/items/{itemId}/guide/refresh', [HowGuideController::class, 'refresh'])
        ->middleware('auth.bearer')->name('trip-requests.plan.items.guide.refresh');
    // TRV-001 Basic Traveler (D1-D6): the activation gate runs first, so these answer 404 until Legal and v3.24 allow them.
    Route::post('/trip-requests/{tripRequestId}/travelers', [BasicTravelerController::class, 'store'])
        ->middleware(['traveler.basic', 'auth.bearer'])->name('trip-requests.travelers.store');
    Route::get('/trip-requests/{tripRequestId}/travelers', [BasicTravelerController::class, 'index'])
        ->middleware(['traveler.basic', 'auth.bearer'])->name('trip-requests.travelers.index');
    Route::get('/trip-requests/{tripRequestId}/travelers/{travelerId}', [BasicTravelerController::class, 'show'])
        ->middleware(['traveler.basic', 'auth.bearer'])->name('trip-requests.travelers.show');
    Route::post('/travelers', [TravelerController::class, 'store'])->middleware('auth.bearer');
    Route::get('/travelers/{travelerId}', [TravelerController::class, 'show'])
        ->middleware('auth.bearer')->name('travelers.show');
});
