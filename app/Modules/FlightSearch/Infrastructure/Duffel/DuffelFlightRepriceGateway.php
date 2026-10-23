<?php

namespace App\Modules\FlightSearch\Infrastructure\Duffel;

use App\Modules\FlightSearch\Contracts\FlightRepriceGateway;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\ProviderTimeout;
use App\Modules\FlightSearch\Domain\RepriceFailed;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;

/**
 * OP-repriceOffer for Duffel offers: re-fetches the offer by its provider reference through the provider's
 * resilience guard (PLT-REL-001). Only canonical offers leave this class; provider errors map to the
 * contract outcomes (timeout -> RC-PROVIDER-TIMEOUT, anything else -> unverified).
 */
final readonly class DuffelFlightRepriceGateway implements FlightRepriceGateway
{
    public function __construct(
        private DuffelFlightProviderAdapter $adapter,
        private ProviderCallGuard $guard,
    ) {}

    public function reprice(string $providerId, FlightOffer $offer): FlightOffer
    {
        $reference = $this->providerReference($providerId, $offer);
        if ($reference === null) {
            throw new RepriceFailed('The offer has no Duffel provider reference.');
        }

        try {
            return $this->guard->call(
                DuffelFlightProviderAdapter::PROVIDER_ID,
                fn (int $timeoutMs): FlightOffer => $this->adapter->retrieveOffer($reference, $timeoutMs),
            );
        } catch (ProviderFailure $failure) {
            throw $failure->kind === ProviderFailureKind::TIMEOUT
                ? new ProviderTimeout('Duffel offer retrieval timed out.')
                : new RepriceFailed('Duffel offer retrieval failed.');
        }
    }

    /** The provider's own offer id recorded in the offer's provenance (never a guess from another source). */
    private function providerReference(string $providerId, FlightOffer $offer): ?string
    {
        if ($providerId !== DuffelFlightProviderAdapter::PROVIDER_ID) {
            return null;
        }
        foreach ($offer->sources as $source) {
            if ($source->providerId === DuffelFlightProviderAdapter::PROVIDER_ID) {
                return $source->providerOfferId;
            }
        }

        return null;
    }
}
