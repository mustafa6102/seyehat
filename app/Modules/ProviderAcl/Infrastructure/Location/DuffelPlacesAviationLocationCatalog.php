<?php

namespace App\Modules\ProviderAcl\Infrastructure\Location;

use App\Modules\ProviderAcl\Contracts\AviationIdentity;
use App\Modules\ProviderAcl\Contracts\AviationLocationCatalog;
use App\Modules\ProviderAcl\Contracts\LocationOption;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * Duffel Places suggestions adapter (https://duffel.com/docs/api/places/schema), flight location discovery only.
 * Duffel `airport` -> kind airport + IATA_AIRPORT, `city` -> kind city + IATA_CITY; every other or incomplete
 * entry is dropped. The Duffel resource id is kept only as catalog identity, never as aviation identity.
 */
final readonly class DuffelPlacesAviationLocationCatalog implements AviationLocationCatalog
{
    public const PROVIDER_ID = 'duffel_places';

    private const ID_PREFIX = 'duffel:';

    private const KIND_BY_TYPE = [
        'airport' => ['airport', AviationIdentity::IATA_AIRPORT],
        'city' => ['city', AviationIdentity::IATA_CITY],
    ];

    public function __construct(
        private Repository $config,
        private Factory $http,
        private ProviderCallGuard $guard,
        private ResiliencePolicies $policies,
    ) {}

    public function isAvailable(): bool
    {
        return $this->token() !== '' && $this->policies->for(self::PROVIDER_ID) !== null;
    }

    public function search(string $query): array
    {
        $query = trim($query);
        $length = mb_strlen($query);
        $maxResults = (int) $this->config->get('services.duffel.max_results');
        if (preg_match('/\p{C}/u', $query) !== 0 || $maxResults < 1
            || $length < (int) $this->config->get('services.duffel.min_query_length')
            || $length > (int) $this->config->get('services.duffel.max_query_length')) {
            return [];
        }

        $rows = $this->request($query)['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw ProviderFailure::unavailable();
        }

        return array_slice(array_values(array_filter(array_map(
            fn (mixed $row): ?LocationOption => is_array($row) ? $this->location($row) : null,
            $rows,
        ))), 0, $maxResults);
    }

    /** @return array<array-key, mixed> */
    private function request(string $query): array
    {
        if (! $this->isAvailable()) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }

        $url = rtrim((string) $this->config->get('services.duffel.base_url'), '/').'/places/suggestions';

        return $this->guard->call(self::PROVIDER_ID, function (int $timeoutMs) use ($url, $query): array {
            try {
                $response = $this->http->timeout($timeoutMs / 1000)->connectTimeout($timeoutMs / 1000)
                    ->withToken($this->token())
                    ->withHeaders(['Duffel-Version' => (string) $this->config->get('services.duffel.version')])
                    ->acceptJson()
                    ->get($url, ['query' => $query]);
            } catch (ConnectionException $exception) {
                // Transport details are never propagated or logged.
                throw str_contains($exception->getMessage(), 'cURL error 28') ? ProviderFailure::timeout() : ProviderFailure::unavailable();
            }

            if ($response->status() === 429) {
                throw ProviderFailure::rateLimited();
            }
            if ($response->status() === 408) {
                throw ProviderFailure::timeout();
            }
            if ($response->clientError()) {
                // Rejected credentials (401/403) or a request the integration should not send (other 4xx) are not
                // transient: non-retryable, reported like a missing configuration, never with provider details.
                throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
            }
            $body = $response->successful() ? $response->json() : null;
            if (! is_array($body)) {
                throw ProviderFailure::unavailable();
            }

            return $body;
        });
    }

    /** @param array<array-key, mixed> $row */
    private function location(array $row): ?LocationOption
    {
        [$kind, $identityType] = self::KIND_BY_TYPE[$row['type'] ?? ''] ?? [null, null];
        $id = $row['id'] ?? null;
        $code = $row['iata_code'] ?? null;
        $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
        $countryCode = $row['iata_country_code'] ?? null;
        $identity = $identityType !== null && is_string($code) ? AviationIdentity::of($identityType, $code) : null;
        $timezone = $kind === null ? null : $this->timezone($row);

        if ($identity === null || ! is_string($id) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $id) !== 1 || $name === ''
            || ! is_string($countryCode) || preg_match('/^[A-Z]{2}$/', $countryCode) !== 1 || $timezone === null) {
            // Incomplete or unknown data is dropped, never completed with guesses.
            return null;
        }

        $cityName = is_string($row['city_name'] ?? null) ? trim($row['city_name']) : '';

        return new LocationOption(
            id: self::ID_PREFIX.$id,
            kind: $kind,
            label: $kind === 'airport' && $cityName !== '' && mb_stripos($name, $cityName) === false ? $name.', '.$cityName : $name,
            countryCode: $countryCode,
            ianaTimezone: $timezone,
            latitude: is_int($row['latitude'] ?? null) || is_float($row['latitude'] ?? null) ? (float) $row['latitude'] : null,
            longitude: is_int($row['longitude'] ?? null) || is_float($row['longitude'] ?? null) ? (float) $row['longitude'] : null,
            aviationIdentity: $identity,
        );
    }

    /**
     * The place's own tz-database zone. A city without one takes the zone only when every listed airport
     * of that city reports the same valid zone; otherwise the entry has no verified timezone.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function timezone(array $row): ?string
    {
        if ($this->isTimezone($row['time_zone'] ?? null)) {
            return $row['time_zone'];
        }
        if (($row['type'] ?? null) !== 'city' || ! is_array($row['airports'] ?? null) || $row['airports'] === []) {
            return null;
        }

        $zones = array_unique(array_map(fn (mixed $airport): mixed => is_array($airport) ? ($airport['time_zone'] ?? null) : null, $row['airports']), SORT_REGULAR);

        return count($zones) === 1 && $this->isTimezone($zones[array_key_first($zones)]) ? $zones[array_key_first($zones)] : null;
    }

    private function isTimezone(mixed $zone): bool
    {
        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true);
    }

    private function token(): string
    {
        return trim((string) $this->config->get('services.duffel.access_token'));
    }
}
