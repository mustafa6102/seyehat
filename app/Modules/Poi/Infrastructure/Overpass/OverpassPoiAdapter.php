<?php

namespace App\Modules\Poi\Infrastructure\Overpass;

use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * osm_overpass (OD-025, v3.15): poi_discovery (center + CFG-POI-SEARCH-RADIUS around query, out center) and poi_verify
 * (re-read of one element by provider_place_id "<type>/<id>"), through the provider's resilience guard. Only SCH-Poi arrays
 * (without poi_id) leave this class; raw Overpass responses and query payloads are neither stored nor logged. Only the
 * center coordinates, radius, tag filter and result limit (discovery) or the OSM element id (verify) are sent (OD-014).
 */
final readonly class OverpassPoiAdapter
{
    public const PROVIDER_ID = 'osm_overpass';

    /** Public instance named by OD-025: development only, never accepted in production (CFG-POI-OVERPASS-ENDPOINT). */
    private const PUBLIC_HOST = 'overpass-api.de';

    private const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    private const DAYS = ['Mo' => 1, 'Tu' => 2, 'We' => 3, 'Th' => 4, 'Fr' => 5, 'Sa' => 6, 'Su' => 7];

    public function __construct(
        private Factory $http,
        private ProviderCallGuard $guard,
        private ResiliencePolicies $policies,
        private Cache $cache,
    ) {}

    /**
     * CFG-POI-OVERPASS-ENDPOINT plus the osm_overpass resilience profile. Returns a concise Turkish reason when the provider
     * must not be called (GAP-POI-CRITERIA-001), or null when it may be.
     */
    public function configurationProblem(): ?string
    {
        $endpoint = $this->endpoint();
        if ($endpoint === null || $this->policies->for(self::PROVIDER_ID) === null) {
            return 'Gezilecek yer araması şu anda kullanılamıyor: arama ayarları yapılandırılmadı.';
        }
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if (app()->environment('production') && ($host === self::PUBLIC_HOST || str_ends_with($host, '.'.self::PUBLIC_HOST))) {
            return 'Gezilecek yer araması şu anda kullanılamıyor: üretim ortamı için onaylı bir sağlayıcı sunucusu yapılandırılmadı.';
        }

        return null;
    }

    /**
     * CFG-POI-DISCOVERY-TAG-FILTER (OPEN): the discovery query scope, or a concise Turkish reason while it is not set or invalid.
     * It is a provider query scope only, never a user preference or category (OD-026); no default subset exists.
     *
     * @return array<string, list<string>>|string
     */
    public function discoveryTagFilter(): array|string
    {
        $filter = config('poi.discovery_tag_filter');
        $valid = is_array($filter) && $filter !== [];
        foreach ($valid ? $filter : [] as $key => $values) {
            $valid = $valid && is_string($key) && preg_match('/^[a-z_:]{1,32}$/', $key) === 1
                && is_array($values) && $values !== [] && array_is_list($values)
                && array_filter($values, fn (mixed $value): bool => ! is_string($value) || preg_match('/^[a-z0-9_]{1,40}$/', $value) !== 1) === [];
        }

        return $valid ? $filter : 'Gezilecek yer araması şu anda kullanılamıyor: arama kapsamı henüz onaylanmadı.';
    }

    /**
     * @param  array<string, mixed>  $criteria  SCH-PoiDiscoveryCriteria
     * @return list<array<string, mixed>> SCH-Poi without poi_id, at most max_results
     *
     * @throws ProviderFailure
     */
    public function discover(array $criteria): array
    {
        $center = $criteria['center_coordinates'];
        $around = sprintf('around:%d,%.7F,%.7F', $criteria['search_radius'], $center['latitude'], $center['longitude']);
        $limit = (int) $criteria['max_results'];
        $filter = $this->discoveryTagFilter();
        if (is_string($filter)) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }
        $statements = '';
        foreach ($filter as $key => $values) {
            $statements .= 'nwr('.$around.')["name"]["'.$key.'"~"^('.implode('|', $values).')$"];';
        }
        $body = $this->query(fn (int $seconds): string => '[out:json][timeout:'.$seconds.'];('.$statements.');out body center '.$limit.';');

        $pois = [];
        foreach ($body['elements'] as $element) {
            $poi = is_array($element) ? $this->poi($element, $body['fetched_at']) : null;
            if ($poi !== null) {
                $pois[] = $poi;
            }
        }

        return array_slice($pois, 0, $limit);
    }

    /**
     * Re-reads one element. Null when the element no longer exists or cannot be mapped (never a closure signal).
     *
     * @return array<string, mixed>|null SCH-Poi without poi_id
     *
     * @throws ProviderFailure
     */
    public function read(string $providerPlaceId): ?array
    {
        if (preg_match('#^(node|way|relation)/([1-9][0-9]{0,18})$#', $providerPlaceId, $match) !== 1) {
            throw ProviderFailure::unavailable();
        }
        $body = $this->query(fn (int $seconds): string => '[out:json][timeout:'.$seconds.'];'.$match[1].'('.$match[2].');out body center;');
        foreach ($body['elements'] as $element) {
            $poi = is_array($element) ? $this->poi($element, $body['fetched_at']) : null;
            if ($poi !== null && $poi['provider_ref']['provider_place_id'] === $providerPlaceId) {
                return $poi;
            }
        }

        return null;
    }

    private function endpoint(): ?string
    {
        $endpoint = config('services.osm_overpass.endpoint');
        if (! is_string($endpoint) || filter_var(trim($endpoint), FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url(trim($endpoint), PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? trim($endpoint) : null;
    }

    /**
     * One guarded Overpass call. A cache lock keeps calls strictly sequential (no parallel requests to the instance).
     *
     * @param  callable(int): string  $query  receives the server-side timeout in seconds
     * @return array{elements: list<mixed>, fetched_at: string}
     *
     * @throws ProviderFailure
     */
    private function query(callable $query): array
    {
        $endpoint = $this->endpoint();
        $policy = $this->policies->for(self::PROVIDER_ID);
        if ($endpoint === null || $policy === null || $this->configurationProblem() !== null) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }
        $lockSeconds = (int) ceil(($policy->timeoutMs * $policy->maxAttempts + $policy->maxDelayMs) / 1000);
        $lock = $this->cache->lock('provider:'.self::PROVIDER_ID.':call', $lockSeconds);
        if (! $lock->get()) {
            throw ProviderFailure::unavailable();
        }
        try {
            return $this->guard->call(self::PROVIDER_ID, fn (int $timeoutMs): array => $this->send($endpoint, $timeoutMs, $query(max(1, intdiv($timeoutMs, 1000)))));
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{elements: list<mixed>, fetched_at: string}
     *
     * @throws ProviderFailure
     */
    private function send(string $endpoint, int $timeoutMs, string $query): array
    {
        try {
            $response = $this->http->timeout($timeoutMs / 1000)->connectTimeout($timeoutMs / 1000)
                ->acceptJson()->asForm()->post($endpoint, ['data' => $query]);
        } catch (ConnectionException $exception) {
            throw str_contains($exception->getMessage(), 'cURL error 28') ? ProviderFailure::timeout() : ProviderFailure::unavailable();
        }

        $status = $response->status();
        if ($status >= 400) {
            // Only the status is logged: never the query, the endpoint or the response body.
            Log::warning('provider.osm_overpass.rejected', ['provider_id' => self::PROVIDER_ID, 'http_status' => $status]);
        }
        if ($status === 429) {
            throw ProviderFailure::rateLimited();
        }
        if ($status === 408 || $status === 504) {
            throw ProviderFailure::timeout();
        }
        if (! $response->successful()) {
            throw ProviderFailure::unavailable();
        }
        $body = $response->json();
        // Overpass reports server-side query failures with HTTP 200 and a "remark"; the text is never copied.
        $remark = is_array($body) && is_string($body['remark'] ?? null) ? $body['remark'] : '';
        if (str_contains($remark, 'runtime error')) {
            throw str_contains($remark, 'timed out') ? ProviderFailure::timeout() : ProviderFailure::unavailable();
        }
        $elements = is_array($body) ? ($body['elements'] ?? null) : null;
        $osmBase = is_array($body) ? ($body['osm3s']['timestamp_osm_base'] ?? null) : null;
        if (! is_array($elements) || ! array_is_list($elements) || ! is_string($osmBase)) {
            throw ProviderFailure::unavailable();
        }
        try {
            $fetchedAt = (new DateTimeImmutable($osmBase))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Throwable) {
            throw ProviderFailure::unavailable();
        }

        return ['elements' => $elements, 'fetched_at' => $fetchedAt];
    }

    /**
     * OD-025 mapping of one OSM element to SCH-Poi (without poi_id); null when the element is unnamed or has no position.
     *
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>|null
     */
    private function poi(array $element, string $fetchedAt): ?array
    {
        $type = $element['type'] ?? null;
        $id = $element['id'] ?? null;
        if (! in_array($type, ['node', 'way', 'relation'], true) || ! is_int($id) || $id < 1) {
            return null;
        }
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        $tag = fn (string $key): ?string => is_string($tags[$key] ?? null) && trim($tags[$key]) !== '' ? trim($tags[$key]) : null;
        $name = $tag('name:tr') ?? $tag('name');
        $position = $type === 'node' ? $element : ($element['center'] ?? null);
        $latitude = is_array($position) ? ($position['lat'] ?? null) : null;
        $longitude = is_array($position) ? ($position['lon'] ?? null) : null;
        if ($name === null || ! is_numeric($latitude) || ! is_numeric($longitude)
            || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return null;
        }

        $placeId = $type.'/'.$id;
        // Every Sourced fact: OSM element URL, osm_base time, never verified (community data does not become FRESH).
        $sourced = fn (mixed $value, string $certainty): array => [
            'value' => $value,
            'certainty' => $certainty,
            'source_reference' => 'https://www.openstreetmap.org/'.$placeId,
            'fetched_at' => $fetchedAt,
            'verified_at' => null,
            'expires_at' => null,
            'verification_status' => 'UNVERIFIED',
        ];
        $hours = $this->openingHours($tag('opening_hours'));
        $admission = $this->admission($tag('fee'), $tag('charge'));
        $street = trim(($tag('addr:street') ?? '').' '.($tag('addr:housenumber') ?? ''));
        $address = implode(', ', array_filter([
            $tag('addr:street') === null ? '' : $street,
            trim(($tag('addr:postcode') ?? '').' '.($tag('addr:city') ?? '')),
        ], fn (string $part): bool => $part !== ''));

        return [
            'provider_ref' => ['provider_id' => self::PROVIDER_ID, 'provider_place_id' => $placeId],
            'name' => $name,
            'categories' => [],
            'location' => [
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'address' => $address === '' ? null : $address,
                'iana_timezone' => null,
            ],
            'business_status' => $sourced('UNKNOWN', 'UNKNOWN'),
            'opening_hours' => $sourced($hours, $hours === null ? 'UNKNOWN' : 'ESTIMATED'),
            'visit_duration' => ['minutes' => null, 'certainty' => 'UNKNOWN', 'source_reference' => null],
            'admission' => $sourced($admission, $admission['fee_required'] === null && $admission['amount'] === null ? 'UNKNOWN' : 'ESTIMATED'),
            // The OSM record is only the provider reference; it is not a booking link (FR-POI-001-02).
            'booking' => $sourced('UNKNOWN', 'UNKNOWN'),
        ];
    }

    /**
     * Only the simple weekly form "<days> <HH:MM-HH:MM>[,...]" with rules separated by ";" (e.g. "Mo-Fr 09:00-17:00;
     * Sa 10:00-14:00"). Anything else (holidays, months, conditions, off/closed, 24/7, overnight, a day in two rules) is null.
     *
     * @return array{weekly: list<array{iso_weekday: int, open: string, close: string}>}|null
     */
    private function openingHours(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $day = '(?:Mo|Tu|We|Th|Fr|Sa|Su)';
        $time = '(?:[01][0-9]|2[0-3]):[0-5][0-9]-(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]|24:00)';
        $weekly = [];
        $seen = [];
        foreach (explode(';', $value) as $rule) {
            if (preg_match('/^('.$day.'(?:-'.$day.')?(?:,'.$day.'(?:-'.$day.')?)*) ('.$time.'(?:,'.$time.')*)$/', trim($rule), $match) !== 1) {
                return null;
            }
            $days = [];
            foreach (explode(',', $match[1]) as $selector) {
                $bounds = explode('-', $selector);
                $from = self::DAYS[$bounds[0]];
                $to = self::DAYS[$bounds[1] ?? $bounds[0]];
                for ($offset = 0; $offset <= ($to - $from + 7) % 7; $offset++) {
                    $days[] = ($from - 1 + $offset) % 7 + 1;
                }
            }
            foreach (array_unique($days) as $weekday) {
                if (isset($seen[$weekday])) {
                    return null;
                }
                $seen[$weekday] = true;
                foreach (explode(',', $match[2]) as $range) {
                    [$open, $close] = explode('-', $range);
                    if ($close <= $open) {
                        return null;
                    }
                    $weekly[] = ['iso_weekday' => $weekday, 'open' => $open, 'close' => $close];
                }
            }
        }
        usort($weekly, fn (array $a, array $b): int => [$a['iso_weekday'], $a['open']] <=> [$b['iso_weekday'], $b['open']]);

        return ['weekly' => $weekly];
    }

    /**
     * fee=yes/no only; charge only as "<amount> <ISO-4217>". A missing fee never means free and an unknown amount is never 0.
     *
     * @return array{fee_required: bool|null, amount: array{minor_units: int, currency: string}|null}
     */
    private function admission(?string $fee, ?string $charge): array
    {
        $feeRequired = match ($fee === null ? null : strtolower($fee)) {
            'yes' => true,
            'no' => false,
            default => null,
        };
        $amount = null;
        if ($charge !== null && preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,3}))? ([A-Z]{3})$/', $charge, $match) === 1) {
            $exponent = in_array($match[3], self::ZERO_DECIMAL_CURRENCIES, true) ? 0
                : (in_array($match[3], self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2);
            $fraction = $match[2] ?? '';
            if (strlen($fraction) <= $exponent) {
                $amount = [
                    'minor_units' => (int) ($match[1].str_pad($fraction, $exponent, '0')),
                    'currency' => $match[3],
                ];
            }
        }

        return ['fee_required' => $feeRequired, 'amount' => $amount];
    }
}
