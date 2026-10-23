<?php

namespace App\Modules\ProviderAcl\Contracts;

/**
 * Canonical country/location lookup for SCH-LocationRef selection (TRIP-001 UI).
 * Implementations normalize provider data; raw payloads never leave the adapter.
 */
interface LocationCatalog
{
    /** True only when credentials and the provider resilience policy are configured. */
    public function isAvailable(): bool;

    /**
     * @return list<CountryOption>
     *
     * @throws ProviderFailure
     */
    public function countries(): array;

    /**
     * @return list<LocationOption>
     *
     * @throws ProviderFailure
     */
    public function search(string $countryCode, string $query): array;

    /** @throws ProviderFailure */
    public function resolve(string $locationId): ?LocationOption;
}
