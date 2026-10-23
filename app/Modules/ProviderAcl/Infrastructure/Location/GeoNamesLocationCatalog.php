<?php

namespace App\Modules\ProviderAcl\Infrastructure\Location;

use App\Modules\ProviderAcl\Contracts\CountryOption;
use App\Modules\ProviderAcl\Contracts\LocationCatalog;
use App\Modules\ProviderAcl\Contracts\LocationOption;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use Collator;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * GeoNames web services adapter (https://www.geonames.org/export/web-services.html, CC BY 4.0).
 * Only canonical entries with a verified IANA timezone and an allowed SCH-LocationRef kind are returned.
 */
final readonly class GeoNamesLocationCatalog implements LocationCatalog
{
    public const PROVIDER_ID = 'geonames';

    private const ID_PREFIX = 'geonames:';

    /** GeoNames feature codes mapped to the location kinds allowed by TRIP-001. */
    private const KIND_BY_FEATURE_CODE = [
        'PPLC' => 'city',
        'PPLA' => 'city',
        'PPLA2' => 'city',
        'PPLA3' => 'city',
        'PPLA4' => 'city',
        'PPL' => 'city',
        'AIRP' => 'airport',
        'RSTN' => 'station',
    ];

    /** GeoNames status values for exhausted credits (daily, hourly, weekly). */
    private const RATE_LIMIT_STATUSES = [18, 19, 20];

    private const NO_RESULT_STATUS = 15;

    public function __construct(
        private Repository $config,
        private Factory $http,
        private ProviderCallGuard $guard,
        private ResiliencePolicies $policies,
    ) {}

    public function isAvailable(): bool
    {
        return $this->username() !== '' && $this->policies->for(self::PROVIDER_ID) !== null;
    }

    public function countries(): array
    {
        $rows = $this->rows($this->request('countryInfoJSON', [['lang', 'tr']]));
        if ($rows === null) {
            throw ProviderFailure::unavailable();
        }

        $countries = [];
        foreach (array_filter($rows, 'is_array') as $row) {
            $code = $row['countryCode'] ?? null;
            $label = $row['countryName'] ?? null;
            if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 && is_string($label) && trim($label) !== '') {
                $countries[] = new CountryOption($code, trim($label));
            }
        }

        usort($countries, fn (CountryOption $a, CountryOption $b): int => $this->compare($a->label, $b->label));

        return $countries;
    }

    public function search(string $countryCode, string $query): array
    {
        $query = trim($query);
        $length = mb_strlen($query);
        $maxRows = (int) $this->config->get('services.geonames.max_rows');
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1 || preg_match('/\p{C}/u', $query) !== 0 || $maxRows < 1
            || $length < (int) $this->config->get('services.geonames.min_query_length')
            || $length > (int) $this->config->get('services.geonames.max_query_length')) {
            return [];
        }

        $parameters = [
            ['name_startsWith', $query],
            ['country', $countryCode],
            ['isNameRequired', 'true'],
            ['maxRows', (string) $maxRows],
            ['style', 'FULL'],
            ['lang', 'tr'],
        ];
        foreach (array_keys(self::KIND_BY_FEATURE_CODE) as $featureCode) {
            $parameters[] = ['featureCode', $featureCode];
        }

        $rows = $this->rows($this->request('searchJSON', $parameters));
        if ($rows === null) {
            throw ProviderFailure::unavailable();
        }

        return array_values(array_filter(array_map(
            fn (mixed $row): ?LocationOption => is_array($row) ? $this->location($row) : null,
            $rows,
        )));
    }

    public function resolve(string $locationId): ?LocationOption
    {
        if (preg_match('/^'.preg_quote(self::ID_PREFIX, '/').'([1-9][0-9]{0,11})$/', $locationId, $match) !== 1) {
            return null;
        }

        $row = $this->request('getJSON', [['geonameId', $match[1]], ['lang', 'tr'], ['style', 'FULL']]);

        return $row === [] ? null : $this->location($row);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $parameters
     * @return array<string, mixed>
     */
    private function request(string $endpoint, array $parameters): array
    {
        if (! $this->isAvailable()) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }

        $parameters[] = ['username', $this->username()];
        $url = rtrim((string) $this->config->get('services.geonames.base_url'), '/').'/'.$endpoint.'?'.implode('&', array_map(
            fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]),
            $parameters,
        ));

        return $this->guard->call(self::PROVIDER_ID, function (int $timeoutMs) use ($url): array {
            try {
                $response = $this->http->timeout($timeoutMs / 1000)->connectTimeout($timeoutMs / 1000)->acceptJson()->get($url);
            } catch (ConnectionException $exception) {
                // The message contains the request URL (with credentials); it is never propagated or logged.
                throw str_contains($exception->getMessage(), 'cURL error 28') ? ProviderFailure::timeout() : ProviderFailure::unavailable();
            }

            if ($response->status() === 429) {
                throw ProviderFailure::rateLimited();
            }
            $body = $response->successful() ? $response->json() : null;
            if (! is_array($body)) {
                throw ProviderFailure::unavailable();
            }

            $status = $body['status']['value'] ?? null;
            if ($status === null) {
                return $body;
            }
            if (in_array($status, self::RATE_LIMIT_STATUSES, true)) {
                throw ProviderFailure::rateLimited();
            }
            if ($status === self::NO_RESULT_STATUS) {
                return [];
            }

            throw ProviderFailure::unavailable();
        });
    }

    /**
     * @param  array<string, mixed>  $body  [] means GeoNames reported "no result"
     * @return array<array-key, mixed>|null null when the payload is malformed
     */
    private function rows(array $body): ?array
    {
        if ($body === []) {
            return [];
        }

        return is_array($body['geonames'] ?? null) ? $body['geonames'] : null;
    }

    /** @param array<array-key, mixed> $row */
    private function location(array $row): ?LocationOption
    {
        $id = $row['geonameId'] ?? null;
        $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
        $countryCode = $row['countryCode'] ?? null;
        $kind = self::KIND_BY_FEATURE_CODE[$row['fcode'] ?? ''] ?? null;
        $timezone = $row['timezone']['timeZoneId'] ?? null;

        if (! is_int($id) || $id < 1 || $name === '' || $kind === null
            || ! is_string($countryCode) || preg_match('/^[A-Z]{2}$/', $countryCode) !== 1
            || ! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            // Incomplete or unknown data is dropped, never completed with guesses.
            return null;
        }

        $region = is_string($row['adminName1'] ?? null) ? trim($row['adminName1']) : '';

        return new LocationOption(
            id: self::ID_PREFIX.$id,
            kind: $kind,
            label: $region !== '' && $region !== $name ? $name.', '.$region : $name,
            countryCode: $countryCode,
            ianaTimezone: $timezone,
            latitude: is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : null,
            longitude: is_numeric($row['lng'] ?? null) ? (float) $row['lng'] : null,
        );
    }

    private function username(): string
    {
        return trim((string) $this->config->get('services.geonames.username'));
    }

    private function compare(string $a, string $b): int
    {
        static $collator = null;
        $collator ??= class_exists(Collator::class) ? new Collator('tr_TR') : false;

        return $collator === false ? strcmp($a, $b) : $collator->compare($a, $b);
    }
}
