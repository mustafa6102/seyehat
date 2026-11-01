<?php

namespace App\Modules\Event\Infrastructure\Ticketmaster;

use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;

/**
 * EVT-001 / OD-EVT-001-TM (v3.22): Ticketmaster Discovery API v2 adapter.
 * API key is NEVER logged or exposed (OD-EVT-001-TM). Only SCH-Event arrays leave this class.
 * Raw Ticketmaster responses, query parameters and the API key are never stored or logged.
 */
final readonly class TicketmasterEventAdapter
{
    public const PROVIDER_ID = 'ticketmaster';

    public function __construct(
        private Factory $http,
        private ProviderCallGuard $guard,
        private ResiliencePolicies $policies,
    ) {}

    /**
     * Returns a concise Turkish reason when the adapter must not be called, or null when it may be.
     */
    public function configurationProblem(): ?string
    {
        $apiKey = config('services.ticketmaster.api_key');
        $baseUrl = config('services.ticketmaster.base_url');
        $approvedHosts = config('services.ticketmaster.approved_hosts');
        if (! is_string($apiKey) || $apiKey === '') {
            return 'Etkinlik araması şu anda kullanılamıyor: servis yapılandırılmadı.';
        }
        if (! is_string($baseUrl) || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            return 'Etkinlik araması şu anda kullanılamıyor: servis adresi yapılandırılmadı.';
        }
        if (! is_array($approvedHosts) || $approvedHosts === []) {
            return 'Etkinlik araması şu anda kullanılamıyor: bilet bağlantısı ana bilgisayar listesi yapılandırılmadı.';
        }
        if ($this->policies->for(self::PROVIDER_ID) === null) {
            return 'Etkinlik araması şu anda kullanılamıyor: dayanıklılık politikası yapılandırılmadı.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $criteria  SCH-EventDiscoveryCriteria
     * @return list<array<string, mixed>>  SCH-Event without event_id, at most max_results
     *
     * @throws ProviderFailure
     */
    public function discover(array $criteria): array
    {
        $apiKey = config('services.ticketmaster.api_key');
        $baseUrl = rtrim((string) config('services.ticketmaster.base_url'), '/');
        $maxResults = (int) config('services.ticketmaster.max_results', 20);
        $freshnessSecs = (int) config('services.ticketmaster.freshness_seconds', 86400);
        $approvedHosts = (array) config('services.ticketmaster.approved_hosts', []);

        if ($this->configurationProblem() !== null) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }

        $params = [
            'apikey' => $apiKey,
            'latlong' => $criteria['center_coordinates']['latitude'].','.$criteria['center_coordinates']['longitude'],
            'radius' => (int) $criteria['search_radius_km'],
            'unit' => 'km',
            'size' => min($maxResults, 200),
            'sort' => 'date,asc',
        ];
        if (isset($criteria['start_date_time'])) {
            $params['startDateTime'] = $criteria['start_date_time'];
        }
        if (isset($criteria['end_date_time'])) {
            $params['endDateTime'] = $criteria['end_date_time'];
        }

        $body = $this->guard->call(self::PROVIDER_ID, fn (int $timeoutMs): array => $this->send($baseUrl, $params, $timeoutMs));

        $fetchedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        $expiresAt = (new DateTimeImmutable("+{$freshnessSecs} seconds", new DateTimeZone('UTC')))->format(DATE_ATOM);

        $events = [];
        $embedded = $body['_embedded'] ?? null;
        $rawEvents = is_array($embedded) ? ($embedded['events'] ?? []) : [];
        foreach ($rawEvents as $raw) {
            $event = is_array($raw) ? $this->mapEvent($raw, $fetchedAt, $expiresAt, $approvedHosts) : null;
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return array_slice($events, 0, $maxResults);
    }

    /**
     * @param  array<string, mixed>  $raw  single Ticketmaster event object
     * @param  list<string>  $approvedHosts
     * @return array<string, mixed>|null  SCH-Event without event_id, or null when required fields are absent
     */
    private function mapEvent(array $raw, string $fetchedAt, string $expiresAt, array $approvedHosts): ?array
    {
        $tmId = $raw['id'] ?? null;
        $name = $raw['name'] ?? null;
        $url = $raw['url'] ?? null;
        if (! is_string($tmId) || $tmId === '' || ! is_string($name) || $name === '') {
            return null;
        }

        // Fact factory: FRESH evidence anchored to fetch time.
        $fact = fn (mixed $value, string $certainty): array => [
            'value' => $value,
            'certainty' => $certainty,
            'source_reference' => is_string($url) ? $url : 'ticketmaster:'.$tmId,
            'verified_at' => $fetchedAt,
            'expires_at' => $expiresAt,
            'verification_status' => 'FRESH',
        ];

        // Status mapping per OD-P8-002: cancelled/postponed cannot be active recommendation.
        $statusCode = $raw['dates']['status']['code'] ?? null;
        $eventStatus = match ($statusCode) {
            'onsale', 'offsale' => 'SCHEDULED',
            default => 'CANCELLED',
        };
        $ticketStatusValue = $statusCode === 'onsale' ? 'AVAILABLE' : null;

        // Dates
        $startDateTime = $raw['dates']['start']['dateTime'] ?? null;
        $endDateTime = $raw['dates']['end']['dateTime'] ?? null;
        $ianaTimezone = $raw['dates']['timezone'] ?? null;

        // Venue and location
        $venueRaw = is_array($raw['_embedded']['venues'] ?? null) ? ($raw['_embedded']['venues'][0] ?? null) : null;
        $venue = is_array($venueRaw) ? $this->mapVenue($venueRaw) : null;
        $latitude = is_array($venueRaw) ? ($venueRaw['location']['latitude'] ?? null) : null;
        $longitude = is_array($venueRaw) ? ($venueRaw['location']['longitude'] ?? null) : null;

        if ($latitude !== null && $longitude !== null) {
            $latitude = (float) $latitude;
            $longitude = (float) $longitude;
            if (abs($latitude) > 90 || abs($longitude) > 180) {
                $latitude = null;
                $longitude = null;
            }
        }

        // Ticket link: HTTPS only, approved host only (CFG-INVENTORY-CTA, OD-P8-002).
        $ticketLink = null;
        if ($eventStatus === 'SCHEDULED' && $ticketStatusValue === 'AVAILABLE' && is_string($url) && str_starts_with($url, 'https://')) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (in_array($host, array_map('strtolower', $approvedHosts), true)) {
                $ticketLink = $fact($url, 'EXACT');
            }
        }

        // Pricing evidence (price ranges, not a purchase guarantee).
        $priceRanges = null;
        if (is_array($raw['priceRanges'] ?? null)) {
            $ranges = [];
            foreach ($raw['priceRanges'] as $range) {
                if (! is_array($range)) {
                    continue;
                }
                $currency = $range['currency'] ?? null;
                $min = $range['min'] ?? null;
                $max = $range['max'] ?? null;
                if (is_string($currency) && (is_numeric($min) || is_numeric($max))) {
                    $ranges[] = [
                        'currency' => $currency,
                        'min' => is_numeric($min) ? (float) $min : null,
                        'max' => is_numeric($max) ? (float) $max : null,
                        'type' => $range['type'] ?? 'standard',
                    ];
                }
            }
            if ($ranges !== []) {
                $priceRanges = $fact($ranges, 'ESTIMATED');
            }
        }

        // Categories from classifications
        $categories = [];
        if (is_array($raw['classifications'] ?? null)) {
            foreach ($raw['classifications'] as $cls) {
                $segment = is_array($cls) ? ($cls['segment']['name'] ?? null) : null;
                if (is_string($segment) && $segment !== '' && $segment !== 'Undefined') {
                    $categories[] = $segment;
                }
                $genre = is_array($cls) ? ($cls['genre']['name'] ?? null) : null;
                if (is_string($genre) && $genre !== '' && $genre !== 'Undefined') {
                    $categories[] = $genre;
                }
            }
            $categories = array_values(array_unique($categories));
        }

        return [
            'provider_ref' => ['provider_id' => self::PROVIDER_ID, 'provider_event_id' => $tmId],
            'name' => $name,
            'venue' => $venue,
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'iana_timezone' => is_string($ianaTimezone) && $ianaTimezone !== '' ? $ianaTimezone : null,
            ],
            'categories' => $categories,
            'status' => $fact($eventStatus, 'EXACT'),
            'ticket_status' => $ticketStatusValue !== null ? $fact($ticketStatusValue, 'EXACT') : null,
            'starts_at' => is_string($startDateTime) && $startDateTime !== '' ? $fact($startDateTime, 'EXACT') : null,
            'ends_at' => is_string($endDateTime) && $endDateTime !== '' ? $fact($endDateTime, 'ESTIMATED') : null,
            'iana_timezone' => is_string($ianaTimezone) && $ianaTimezone !== '' ? $ianaTimezone : null,
            'ticket_link' => $ticketLink,
            'price_ranges' => $priceRanges,
        ];
    }

    /**
     * @param  array<string, mixed>  $venueRaw
     * @return array<string, mixed>
     */
    private function mapVenue(array $venueRaw): array
    {
        $name = is_string($venueRaw['name'] ?? null) ? $venueRaw['name'] : null;
        $city = is_array($venueRaw['city'] ?? null) ? ($venueRaw['city']['name'] ?? null) : null;
        $country = is_array($venueRaw['country'] ?? null) ? ($venueRaw['country']['name'] ?? null) : null;
        $address = is_array($venueRaw['address'] ?? null) ? ($venueRaw['address']['line1'] ?? null) : null;

        return array_filter([
            'name' => $name,
            'city' => $city,
            'country' => $country,
            'address' => $address,
        ], fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $params  includes apikey — never logged
     * @return array<string, mixed>
     *
     * @throws ProviderFailure
     */
    private function send(string $baseUrl, array $params, int $timeoutMs): array
    {
        try {
            $response = $this->http
                ->timeout($timeoutMs / 1000)
                ->connectTimeout($timeoutMs / 1000)
                ->acceptJson()
                ->get($baseUrl.'/events.json', $params);
        } catch (ConnectionException $exception) {
            throw str_contains($exception->getMessage(), 'cURL error 28') ? ProviderFailure::timeout() : ProviderFailure::unavailable();
        }

        $status = $response->status();
        if ($status >= 400) {
            // Only the status is logged; never the URL, params (which include the apikey) or response body.
            Log::warning('provider.ticketmaster.rejected', ['provider_id' => self::PROVIDER_ID, 'http_status' => $status]);
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
        if (! is_array($body)) {
            throw ProviderFailure::unavailable();
        }

        return $body;
    }
}
