<?php

use App\Http\Controllers\FlightPagesController;
use App\Http\Controllers\LocationCatalogController;
use App\Http\Controllers\MyTripRequestsController;
use App\Http\Controllers\OidcLoginController;
use App\Http\Controllers\ProductionDiagnosticsController;
use App\Http\Controllers\TripPlannerController;
use App\Http\Controllers\TripPlanWebController;
use App\Http\Middleware\RequireProductionDiagnosticsAccess;
use App\Modules\Accommodation\Infrastructure\Http\AccommodationSearchController;
use App\Modules\Accommodation\Infrastructure\Http\AccommodationSelectionController;
use App\Modules\FlightSearch\Infrastructure\Http\FlightSearchController;
use App\Modules\FlightSearch\Infrastructure\Http\FlightSelectionController;
use App\Modules\Poi\Infrastructure\Http\PoiDiscoveryController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', [TripPlannerController::class, 'index'])->name('trip-planner.index');
Route::post('/planla', [TripPlannerController::class, 'store'])->name('trip-planner.store');
Route::get('/taleplerim', [MyTripRequestsController::class, 'index'])->name('trip-requests.mine');
// ADR-010 browser login (OIDC Authorization Code + PKCE); the session principal is used by web routes only.
Route::get('/giris', [OidcLoginController::class, 'start'])->name('auth.login');
Route::get('/giris/donus', [OidcLoginController::class, 'callback'])->name('auth.callback');
Route::post('/cikis', [OidcLoginController::class, 'logout'])->name('auth.logout');
// tripRequestId is an opaque string (SCH-TripRequest / OpenAPI); the database store rejects non-digit ids itself.
Route::get('/planlar/{tripRequestId}',[TripPlannerController::class, 'show'])->name('trip-planner.show');
Route::get('/planlar/{tripRequestId}/plan', [TripPlannerController::class, 'plan'])->name('trip-plan.show');
// v3.16 unscheduled FLEXIBLE POI plan items for the signed-in owner (session + CSRF; same guard and TripPlanService as the API).
Route::post('/planlar/{tripRequestId}/plan/gezilecek-yerler', [TripPlanWebController::class, 'store'])->name('trip-plan.pois.store');
Route::delete('/planlar/{tripRequestId}/plan/gezilecek-yerler/{itemId}', [TripPlanWebController::class, 'destroy'])->name('trip-plan.pois.destroy');
Route::get('/planlar/{tripRequestId}/ucuslar', [FlightPagesController::class, 'search'])->name('flights.search');
Route::get('/teklifler/{offerId}', [FlightPagesController::class, 'offer'])->name('flights.offer');
// OD-028 (v3.17, option A): web channel of the TripRequest-owned operations for the signed-in browser session. Same paths,
// schemas and handlers as /api/v1 (TripRequestOwnership + application services); web session + CSRF instead of bearerAuth.
Route::prefix('web-api/v1')->middleware('auth.web-session')->name('web-api.')->group(function (): void {
    Route::post('/flight-search-jobs', [FlightSearchController::class, 'store'])->name('flight-search-jobs.store');
    Route::get('/flight-search-jobs/{searchId}', [FlightSearchController::class, 'show'])->name('flight-search-jobs.show');
    Route::get('/trip-requests/{tripRequestId}/flight-selection', [FlightSelectionController::class, 'show'])
        ->whereNumber('tripRequestId')->name('flight-selection.show');
    Route::put('/trip-requests/{tripRequestId}/flight-selection', [FlightSelectionController::class, 'store'])
        ->whereNumber('tripRequestId')->name('flight-selection.store');
    Route::post('/trip-requests/{tripRequestId}/flight-selection/reconfirm', [FlightSelectionController::class, 'reconfirm'])
        ->whereNumber('tripRequestId')->name('flight-selection.reconfirm');
    Route::post('/accommodation-search-jobs', [AccommodationSearchController::class, 'store'])->name('accommodation-search-jobs.store');
    Route::get('/accommodation-search-jobs/{searchId}', [AccommodationSearchController::class, 'show'])->name('accommodation-search-jobs.show');
    Route::get('/trip-requests/{tripRequestId}/accommodation-selection', [AccommodationSelectionController::class, 'show'])
        ->whereNumber('tripRequestId')->name('accommodation-selection.show');
    Route::put('/trip-requests/{tripRequestId}/accommodation-selection', [AccommodationSelectionController::class, 'store'])
        ->whereNumber('tripRequestId')->name('accommodation-selection.store');
    Route::post('/trip-requests/{tripRequestId}/accommodation-selection/reconfirm', [AccommodationSelectionController::class, 'reconfirm'])
        ->whereNumber('tripRequestId')->name('accommodation-selection.reconfirm');
    Route::post('/poi-discovery-jobs', [PoiDiscoveryController::class, 'store'])->name('poi-discovery-jobs.store');
    Route::get('/poi-discovery-jobs/{searchId}', [PoiDiscoveryController::class, 'show'])->name('poi-discovery-jobs.show');
});
Route::get('/konumlar/ulkeler', [LocationCatalogController::class, 'countries'])->name('locations.countries');
Route::get('/konumlar/ara', [LocationCatalogController::class, 'search'])->name('locations.search');
Route::get('/konumlar/cozumle', [LocationCatalogController::class, 'resolve'])->name('locations.resolve');

// Frontend-only style guide with static sample content; never registered in production.
if (! app()->isProduction()) {
    Route::view('/tasarim-sistemi', 'design-system')->name('design-system');
}

// Header-token endpoints: no session, cookies or CSRF (a POST would otherwise fail with 419 and reveal the route).
Route::middleware(RequireProductionDiagnosticsAccess::class)->withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
])->group(function (): void {
    Route::get('/internal/production-diagnostics', [ProductionDiagnosticsController::class, 'show']);
    Route::post('/internal/production-diagnostics/logging-probe', [ProductionDiagnosticsController::class, 'probe']);
});
