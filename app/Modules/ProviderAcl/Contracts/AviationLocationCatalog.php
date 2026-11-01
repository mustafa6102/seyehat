<?php

namespace App\Modules\ProviderAcl\Contracts;

/**
 * Flight-capable location discovery (OD-022 LOCATION, v3.8). Every returned entry carries a
 * provider-independent AviationIdentity; raw provider payloads and resource ids never leave the adapter.
 * General (non-flight) discovery stays with LocationCatalog.
 */
interface AviationLocationCatalog
{
    /** True only when credentials and the provider resilience policy are configured. */
    public function isAvailable(): bool;

    /**
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2; narrows the entries before the result limit is applied
     * @return list<LocationOption> airport/city entries whose aviationIdentity is set
     *
     * @throws ProviderFailure
     */
    public function search(string $query, ?string $countryCode = null): array;
}
