<?php

namespace App\Providers;

use App\Modules\Accommodation\Application\AccommodationSelectionService;
use App\Modules\Accommodation\Contracts\AccommodationSelectionReader;
use App\Modules\Api\Application\TripRequestApplicationService;
use App\Modules\Api\Application\TripRequestOwnershipGuard;
use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Domain\TripRequest\TripRequestRepository;
use App\Modules\Api\Infrastructure\Persistence\DatabaseTripRequestRepository;
use App\Modules\Authorization\Application\MatrixAccessControl;
use App\Modules\Authorization\Application\OidcBearerAuthenticator;
use App\Modules\Authorization\Contracts\AccessControl;
use App\Modules\Authorization\Contracts\BearerAuthenticator;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use App\Modules\Authorization\Domain\AccessAuditLog;
use App\Modules\Authorization\Infrastructure\LogAccessAuditLog;
use App\Modules\Authorization\Infrastructure\Web\SessionWebPrincipalResolver;
use App\Modules\CandidateGenerator\Contracts\CandidateSeedSource;
use App\Modules\CandidateGenerator\Contracts\PruningEvidence;
use App\Modules\CandidateGenerator\Contracts\SearchBudgetPolicy;
use App\Modules\CandidateGenerator\Infrastructure\ConfigCandidateSeedSource;
use App\Modules\CandidateGenerator\Infrastructure\ConfigPruningEvidence;
use App\Modules\CandidateGenerator\Infrastructure\ConfigSearchBudgetPolicy;
use App\Modules\FlightSearch\Application\FlightOfferAggregator;
use App\Modules\FlightSearch\Application\FlightSearchDispatcher;
use App\Modules\FlightSearch\Application\FlightSelectionService;
use App\Modules\FlightSearch\Application\LocationGatedFlightSearchExecutor;
use App\Modules\FlightSearch\Application\ResilientFlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightRepriceGateway;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightSelectionReader;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use App\Modules\FlightSearch\Infrastructure\Adapters\UnavailableFlightRepriceGateway;
use App\Modules\FlightSearch\Infrastructure\Adapters\UnavailableFlightSearchExecutor;
use App\Modules\FlightSearch\Infrastructure\Duffel\DuffelFlightProviderAdapter;
use App\Modules\FlightSearch\Infrastructure\Duffel\DuffelFlightRepriceGateway;
use App\Modules\FlightSearch\Infrastructure\Persistence\CacheFlightOfferRepository;
use App\Modules\FlightSearch\Infrastructure\Persistence\DatabaseFlightSearchJobRepository;
use App\Modules\FlightSearch\Infrastructure\Queue\LaravelFlightSearchDispatcher;
use App\Modules\Poi\Contracts\PoiReader;
use App\Modules\Poi\Infrastructure\Persistence\DatabasePoiRepository;
use App\Modules\ProviderAcl\Application\ProviderCapabilityMatrixService;
use App\Modules\ProviderAcl\Application\ResilientProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\AviationLocationCatalog;
use App\Modules\ProviderAcl\Contracts\LocationCatalog;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use App\Modules\ProviderAcl\Domain\ProviderCapabilityRepository;
use App\Modules\ProviderAcl\Infrastructure\Location\DuffelPlacesAviationLocationCatalog;
use App\Modules\ProviderAcl\Infrastructure\Location\GeoNamesLocationCatalog;
use App\Modules\ProviderAcl\Infrastructure\Persistence\DatabaseProviderCapabilityRepository;
use App\Modules\ProviderAcl\Infrastructure\Resilience\ConfigResiliencePolicies;
use App\Modules\Traveler\Application\TravelerApplicationService;
use App\Modules\Traveler\Contracts\TravelerService;
use App\Modules\Traveler\Domain\SensitiveDataAccessLog;
use App\Modules\Traveler\Domain\TravelerRepository;
use App\Modules\Traveler\Infrastructure\Logging\LogSensitiveDataAccessLog;
use App\Modules\Traveler\Infrastructure\Persistence\DatabaseTravelerRepository;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\Infrastructure\DatabaseOutboxWriter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TripRequestRepository::class, DatabaseTripRequestRepository::class);
        $this->app->bind(TripRequestService::class, TripRequestApplicationService::class);
        // ADR-010 / PLT-AUTH-001 (v3.16): OIDC bearer authentication, TripRequest ownership, and no web principal until the
        // browser login is integrated.
        $this->app->bind(BearerAuthenticator::class, OidcBearerAuthenticator::class);
        $this->app->bind(TripRequestOwnership::class, TripRequestOwnershipGuard::class);
        $this->app->bind(WebPrincipalResolver::class, SessionWebPrincipalResolver::class);
        $this->app->bind(FlightOfferRepository::class, CacheFlightOfferRepository::class);
        // FLT-REPRICE-001: Duffel offers are re-fetched only with a credential; the service still requires
        // production 'reprice' capability evidence before any gateway call.
        $this->app->bind(FlightRepriceGateway::class, fn ($app): FlightRepriceGateway => DuffelFlightProviderAdapter::isConfigured($app->make('config'))
            ? $app->make(DuffelFlightRepriceGateway::class)
            : new UnavailableFlightRepriceGateway);
        $this->app->bind(FlightSearchJobRepository::class, DatabaseFlightSearchJobRepository::class);
        $this->app->bind(FlightSearchDispatcher::class, LaravelFlightSearchDispatcher::class);
        $this->app->bind(FlightSelectionReader::class, FlightSelectionService::class);
        $this->app->bind(AccommodationSelectionReader::class, AccommodationSelectionService::class);
        // Adapters register under this tag once OD-001 selects a provider; until then search stays unavailable.
        $this->app->bind(FlightSearchExecutor::class, function ($app): FlightSearchExecutor {
            $adapters = iterator_to_array($app->tagged('flight.provider_adapters'), false);
            // Duffel joins with a credential; explicit criteria or the legacy policy decide passenger support.
            try {
                $duffelSearchSupported = $app->make(ProviderCapabilityMatrix::class)
                    ->productionCapability(DuffelFlightProviderAdapter::PROVIDER_ID, 'search')->supported;
            } catch (Throwable) {
                $duffelSearchSupported = false;
            }
            if (DuffelFlightProviderAdapter::isConfigured($app->make('config')) && $duffelSearchSupported) {
                $adapters[] = $app->make(DuffelFlightProviderAdapter::class);
            }

            return $adapters === []
                ? new UnavailableFlightSearchExecutor
                : new LocationGatedFlightSearchExecutor(new ResilientFlightSearchExecutor($adapters, $app->make(ProviderCallGuard::class), new FlightOfferAggregator));
        });
        $this->app->bind(ResiliencePolicies::class, ConfigResiliencePolicies::class);
        $this->app->bind(ProviderCallGuard::class, ResilientProviderCallGuard::class);
        $this->app->bind(LocationCatalog::class, GeoNamesLocationCatalog::class);
        $this->app->bind(AviationLocationCatalog::class, DuffelPlacesAviationLocationCatalog::class);
        $this->app->bind(AccessControl::class, MatrixAccessControl::class);
        $this->app->bind(AccessAuditLog::class, LogAccessAuditLog::class);
        $this->app->bind(\App\Modules\Authorization\Contracts\AuditIdentity::class, \App\Modules\Authorization\Infrastructure\HmacAuditIdentity::class);
        $this->app->bind(\App\Modules\Authorization\Contracts\AccessOutcomeLog::class, \App\Modules\Authorization\Infrastructure\LogAccessOutcomeLog::class);
        $this->app->bind(ProviderCapabilityRepository::class, DatabaseProviderCapabilityRepository::class);
        $this->app->bind(ProviderCapabilityMatrix::class, ProviderCapabilityMatrixService::class);
        // RCG-001: deployment-supplied seeds, pruning evidence and budgets are fail-closed. CandidateGenerator itself
        // stays unbound until an active contract defines leg criteria (date/passengers) and authorizes provider calls.
        $this->app->bind(CandidateSeedSource::class, ConfigCandidateSeedSource::class);
        $this->app->bind(PruningEvidence::class, ConfigPruningEvidence::class);
        $this->app->bind(SearchBudgetPolicy::class, ConfigSearchBudgetPolicy::class);
        $this->app->bind(OutboxWriter::class, DatabaseOutboxWriter::class);
        // v3.16 plan increment reads stored canonical POIs through the Poi module contract (no provider call).
        $this->app->bind(PoiReader::class, DatabasePoiRepository::class);
        // v3.22 EVT-001: plan increment reads stored canonical events through the Event module contract (no provider call).
        $this->app->bind(\App\Modules\Event\Contracts\EventReader::class, \App\Modules\Event\Infrastructure\Persistence\DatabaseEventRepository::class);
        $this->app->bind(TravelerRepository::class, DatabaseTravelerRepository::class);
        $this->app->bind(TravelerService::class, TravelerApplicationService::class);
        // TRV-001 Basic Traveler (D1-D6): reachable only behind the default-closed BasicTravelerActivation.
        $this->app->bind(\App\Modules\Traveler\Contracts\BasicTravelerActivation::class, \App\Modules\Traveler\Application\BasicTravelerActivationGate::class);
        $this->app->bind(\App\Modules\Traveler\Domain\TravelerEnrollmentStore::class, \App\Modules\Traveler\Infrastructure\Persistence\DatabaseTravelerEnrollmentStore::class);
        $this->app->bind(\App\Modules\Traveler\Contracts\TripTravelerEnrollment::class, \App\Modules\Traveler\Application\TripTravelerEnrollmentService::class);
        $this->app->bind(\App\Modules\Traveler\Contracts\TravelerSubjectRequests::class, \App\Modules\Traveler\Application\TravelerSubjectRequestService::class);
        $this->app->bind(\App\Modules\Traveler\Domain\TravelerSubjectRequestStore::class, \App\Modules\Traveler\Infrastructure\Persistence\DatabaseTravelerSubjectRequestStore::class);
        // TRV-001 sensitive profile: no route uses it; the KEK and binding defaults keep it closed until ADR-010 is approved.
        $this->app->bind(\App\Modules\Traveler\Contracts\SensitiveTravelerActivation::class, \App\Modules\Traveler\Application\SensitiveTravelerActivationGate::class);
        $this->app->bind(\App\Modules\Traveler\Contracts\SensitiveTravelerProfiles::class, \App\Modules\Traveler\Application\SensitiveTravelerProfileService::class);
        $this->app->bind(\App\Modules\Traveler\Contracts\TravelerSubjectBinder::class, \App\Modules\Traveler\Application\TravelerSubjectBindingService::class);
        $this->app->bind(\App\Modules\Traveler\Domain\Encryption\KeyEncryptionKeys::class, fn ($app) => $this->configuredProvider(
            $app, 'traveler_sensitive.key_provider', \App\Modules\Traveler\Domain\Encryption\KeyEncryptionKeys::class, \App\Modules\Traveler\Infrastructure\Encryption\UnconfiguredKeyEncryptionKeys::class,
        ));
        $this->app->bind(\App\Modules\Traveler\Domain\TravelerIdentityProofs::class, fn ($app) => $this->configuredProvider(
            $app, 'traveler_sensitive.identity_proof_provider', \App\Modules\Traveler\Domain\TravelerIdentityProofs::class, \App\Modules\Traveler\Infrastructure\UnconfiguredTravelerIdentityProofs::class,
        ));
        $this->app->bind(\App\Modules\Traveler\Domain\Encryption\SensitiveTravelerVault::class, \App\Modules\Traveler\Infrastructure\Persistence\DatabaseSensitiveTravelerVault::class);
        $this->app->bind(\App\Modules\Traveler\Domain\TravelerSubjectBindings::class, \App\Modules\Traveler\Infrastructure\Persistence\DatabaseTravelerSubjectBindings::class);
        $this->app->bind(\App\Modules\TripBuilder\Contracts\TripDirectory::class, \App\Modules\TripBuilder\Infrastructure\Persistence\DatabaseTripDirectory::class);
        $this->app->bind(SensitiveDataAccessLog::class, LogSensitiveDataAccessLog::class);
    }

    /**
     * Bootstrap any application services.
     */
    /** A provider is used only when configured in code and of the required type; anything else stays the refusing default. */
    private function configuredProvider(\Illuminate\Contracts\Foundation\Application $app, string $configKey, string $contract, string $default): object
    {
        $provider = config($configKey);

        return $app->make(is_string($provider) && is_subclass_of($provider, $contract) ? $provider : $default);
    }

    public function boot(): void
    {
        // Header login/logout state only (ADR-010 browser session); no identity detail reaches the view.
        View::composer('layouts.app', function ($view): void {
            $view->with('webSignedIn', $this->app->make(WebPrincipalResolver::class)->resolve(request()) !== null);
        });
    }
}
