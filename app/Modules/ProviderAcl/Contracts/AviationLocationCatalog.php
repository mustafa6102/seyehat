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
     * @return list<LocationOption> airport/city entries whose aviationIdentity is set
     *
     * @throws ProviderFailure
     */
    public function search(string $query): array;
}
