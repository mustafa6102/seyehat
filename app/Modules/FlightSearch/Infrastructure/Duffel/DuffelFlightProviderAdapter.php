<?php

namespace App\Modules\FlightSearch\Infrastructure\Duffel;

use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\ScreensFlightCriteria;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightSegment;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\FlightSearch\Domain\SegmentTime;
use App\Modules\FlightSearch\Infrastructure\Adapters\AbstractFlightProviderAdapter;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Duffel Flights (POST /air/offer_requests, https://duffel.com/docs/api/v2/offer-requests/create-offer-request).
 * The only place that knows Duffel's request/response shapes; everything leaving this class is canonical.
 *
 * Deliberately narrow, because the SSOT leaves these OPEN (OD-020/OD-021 traveler passenger semantics,
 * OD-022 cabin class and flexible-date selection, canonical FlightOffer without slices):
 *  - only ONE_WAY criteria with an EXACT outbound date are sent; anything else fails before any HTTP call;
 *  - explicit all-adult search composition is safe; child/infant counts without age are rejected;
 *  - legacy count-only requests require the explicit deploy-time policy ALL_ADULT;
 *  - no cabin class is sent.
 */
final class DuffelFlightProviderAdapter extends AbstractFlightProviderAdapter implements ScreensFlightCriteria
{
    public const PROVIDER_ID = 'duffel_flights';

    /** Legacy count-only compatibility policy; explicit passenger composition is never derived from it. */
    public const PASSENGER_POLICY_ALL_ADULT = 'ALL_ADULT';

    private const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public function __construct(
        ProviderCapabilityMatrix $capabilityMatrix,
        private readonly Repository $config,
        private readonly Factory $http,
    ) {
        parent::__construct($capabilityMatrix, self::PROVIDER_ID);
    }

    /** A credential registers the adapter; criteria decide whether explicit or legacy passengers are safe. */
    public static function isConfigured(Repository $config): bool
    {
        return trim((string) $config->get('services.duffel.access_token')) !== '';
    }

    public function supportsCriteria(FlightSearchCriteria $criteria): bool
    {
        return $this->expressibleSlices($criteria) !== null;
    }

    protected function fetchRawOffers(FlightSearchCriteria $criteria): iterable
    {
        $slices = $this->slices($criteria);
        if (! self::isConfigured($this->config)) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }

        $body = $this->request($slices, $this->passengers($criteria));
        $offers = $body['data']['offers'] ?? null;
        if (! is_array($body['data'] ?? null) || ! is_array($offers) || ! array_is_list($offers)) {
            throw ProviderFailure::unavailable();
        }

        $fetchedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $maxOffers = max(1, (int) $this->config->get('services.duffel.flights.max_offers'));
        $parsed = [];
        foreach ($offers as $offer) {
            $canonical = is_array($offer) ? $this->parseOffer($offer, $fetchedAt) : null;
            if ($canonical !== null) {
                $parsed[] = $canonical;
            }
            if (count($parsed) >= $maxOffers) {
                break;
            }
        }

        return $parsed;
    }

    protected function normalizeRawOffer(mixed $raw): FlightOffer
    {
        return $this->canonicalOffer(
            providerOfferId: $raw['id'],
            providerRole: 'AGGREGATOR',
            distributionChannel: null,
            fetchedAt: $raw['fetched_at'],
            // The offer was just returned live by the provider with its own expiry, so it is provider-verified as of fetch.
            verifiedAt: $raw['fetched_at'],
            expiresAt: $raw['expires_at'],
            price: $raw['price'],
            priceCertainty: 'EXACT',
            priceCompleteness: 'COMPLETE',
            baggage: $raw['baggage'],
            segments: $raw['segments'],
        );
    }

    /**
     * Canonical criteria -> Duffel slices. Aviation codes come only from the canonical, catalog-issued identity.
     *
     * @return list<array{origin: string, destination: string, departure_date: string}>
     */
    private function slices(FlightSearchCriteria $criteria): array
    {
        return $this->expressibleSlices($criteria) ?? throw ProviderFailure::of(ProviderFailureKind::UNSUPPORTED_CRITERIA);
    }

    /** @return list<array{origin: string, destination: string, departure_date: string}>|null */
    private function expressibleSlices(FlightSearchCriteria $criteria): ?array
    {
        $origin = $criteria->originPlace['aviation_identity']['code'] ?? null;
        $destination = $criteria->destinationPlace['aviation_identity']['code'] ?? null;
        $date = $criteria->outboundDate['date'] ?? null;
        if ($criteria->journeyType !== 'ONE_WAY' || ($criteria->outboundDate['type'] ?? null) !== 'EXACT'
            || ! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || ! is_string($origin) || preg_match('/^[A-Z]{3}$/', $origin) !== 1
            || ! is_string($destination) || preg_match('/^[A-Z]{3}$/', $destination) !== 1
            || $criteria->travelerCount < 1 || $criteria->travelerCount > 9 || $this->passengers($criteria) === null) {
            // Criteria the canonical contract cannot yet express for this provider never cause an HTTP call.
            return null;
        }

        return [['origin' => $origin, 'destination' => $destination, 'departure_date' => $date]];
    }

    /** @return list<array{type:string}>|null */
    private function passengers(FlightSearchCriteria $criteria): ?array
    {
        $composition = $criteria->passengerComposition;
        if ($composition === null) {
            return $this->config->get('services.duffel.flights.passenger_policy') === self::PASSENGER_POLICY_ALL_ADULT
                ? array_fill(0, $criteria->travelerCount, ['type' => 'adult'])
                : null;
        }
        if (($composition['adults'] ?? -1) !== $criteria->travelerCount
            || ($composition['children'] ?? -1) !== 0 || ($composition['infants'] ?? -1) !== 0) {
            return null;
        }

        return array_fill(0, $composition['adults'], ['type' => 'adult']);
    }

    /**
     * @param  list<array<string, string>>  $slices
     * @return array<array-key, mixed>
     */
    private function request(array $slices, array $passengers): array
    {
        $timeoutMs = $this->callTimeoutMs() ?? 30000;
        $query = http_build_query([
            'return_offers' => 'true',
            // The provider's per-airline wait stays inside our own call budget; documented range 2000-60000 ms.
            'supplier_timeout' => max(2000, min(60000, $timeoutMs - 5000)),
        ]);
        $url = rtrim((string) $this->config->get('services.duffel.base_url'), '/').'/air/offer_requests?'.$query;
        $payload = ['data' => [
            'slices' => $slices,
            'passengers' => $passengers,
        ]];

        return $this->send($url, $timeoutMs, $payload);
    }

    /**
     * FLT-REPRICE-001: the provider's latest version of a previously returned offer (GET /air/offers/{id},
     * https://duffel.com/docs/guides/getting-started-with-flights "Selecting an offer from the search results"),
     * normalized exactly like a search result. Anything incomplete, malformed or for another offer id is a
     * provider failure; nothing is completed from the earlier snapshot.
     *
     * @throws ProviderFailure
     */
    public function retrieveOffer(string $providerOfferId, int $timeoutMs): FlightOffer
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $providerOfferId) !== 1) {
            throw ProviderFailure::unavailable();
        }
        if (! self::isConfigured($this->config)) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }

        $url = rtrim((string) $this->config->get('services.duffel.base_url'), '/').'/air/offers/'.$providerOfferId
            .'?'.http_build_query(['return_available_services' => 'false']);
        $body = $this->send($url, $timeoutMs, null);
        $offer = $body['data'] ?? null;
        $parsed = is_array($offer) ? $this->parseOffer($offer, new DateTimeImmutable('now', new DateTimeZone('UTC'))) : null;
        if ($parsed === null || $parsed['id'] !== $providerOfferId) {
            throw ProviderFailure::unavailable();
        }

        return $this->normalizeRawOffer($parsed);
    }

    /**
     * One Duffel call (POST with a JSON payload, otherwise GET) mapped to canonical failures.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<array-key, mixed>
     */
    private function send(string $url, int $timeoutMs, ?array $payload): array
    {
        try {
            $pending = $this->http->timeout($timeoutMs / 1000)->connectTimeout(min($timeoutMs, 10000) / 1000)
                ->withToken(trim((string) $this->config->get('services.duffel.access_token')))
                ->withHeaders(['Duffel-Version' => (string) $this->config->get('services.duffel.version')])
                ->acceptJson();
            $response = $payload === null ? $pending->get($url) : $pending->asJson()->post($url, $payload);
        } catch (ConnectionException $exception) {
            // Transport details are never propagated or logged.
            throw str_contains($exception->getMessage(), 'cURL error 28') ? ProviderFailure::timeout() : ProviderFailure::unavailable();
        }

        $status = $response->status();
        if ($status >= 400) {
            $this->logRejection($status, $response->json());
        }
        if ($status === 429) {
            throw ProviderFailure::rateLimited();
        }
        if ($status === 408 || $status === 504) {
            throw ProviderFailure::timeout();
        }
        if ($response->serverError()) {
            throw ProviderFailure::unavailable();
        }
        if ($response->clientError()) {
            // Rejected credentials (401/403) or a request the integration should not send (other 4xx) are not
            // transient: non-retryable and reported without provider details.
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }
        $body = $response->successful() ? $response->json() : null;
        if (! is_array($body)) {
            throw ProviderFailure::unavailable();
        }

        return $body;
    }

    /** Only the status and Duffel's enum-like error type/code are logged, and only when they look like identifiers. */
    private function logRejection(int $status, mixed $body): void
    {
        $error = is_array($body) && is_array($body['errors'][0] ?? null) ? $body['errors'][0] : [];
        $safe = fn (mixed $value): ?string => is_string($value) && preg_match('/^[a-z0-9_]{1,64}$/', $value) === 1 ? $value : null;
        Log::warning('provider.duffel_flights.rejected', [
            'provider_id' => self::PROVIDER_ID,
            'http_status' => $status,
            'error_type' => $safe($error['type'] ?? null),
            'error_code' => $safe($error['code'] ?? null),
        ]);
    }

    /**
     * Provider offer -> canonical building blocks, or null when any authoritative field is missing or malformed
     * (an incomplete offer is dropped, never completed with a guess).
     *
     * @param  array<array-key, mixed>  $offer
     * @return array{id: string, fetched_at: DateTimeImmutable, expires_at: ?DateTimeImmutable, price: Money, baggage: BaggageAllowance, segments: list<FlightSegment>}|null
     */
    private function parseOffer(array $offer, DateTimeImmutable $fetchedAt): ?array
    {
        try {
            $id = $offer['id'] ?? null;
            $slices = $offer['slices'] ?? null;
            if (! is_string($id) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $id) !== 1 || ! is_array($slices) || count($slices) !== 1) {
                return null;
            }
            $price = $this->money($offer['total_amount'] ?? null, $offer['total_currency'] ?? null);
            $rawSegments = $slices[0]['segments'] ?? null;
            if ($price === null || ! is_array($rawSegments) || $rawSegments === []) {
                return null;
            }

            $segments = [];
            foreach ($rawSegments as $rawSegment) {
                $segment = is_array($rawSegment) ? $this->segment($rawSegment) : null;
                if ($segment === null) {
                    return null;
                }
                $segments[] = $segment;
            }

            return [
                'id' => $id,
                'fetched_at' => $fetchedAt,
                'expires_at' => $this->instant($offer['expires_at'] ?? null),
                'price' => $price,
                'baggage' => $this->baggage($rawSegments),
                'segments' => $segments,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $segment */
    private function segment(array $segment): ?FlightSegment
    {
        $operating = $segment['operating_carrier']['iata_code'] ?? null;
        $operatingNumber = $segment['operating_carrier_flight_number'] ?? null;
        $marketing = $segment['marketing_carrier']['iata_code'] ?? null;
        $marketingNumber = $segment['marketing_carrier_flight_number'] ?? null;
        $departure = $this->segmentTime($segment['departing_at'] ?? null, $segment['origin']['time_zone'] ?? null);
        $arrival = $this->segmentTime($segment['arriving_at'] ?? null, $segment['destination']['time_zone'] ?? null);
        $code = fn (mixed $value): bool => is_string($value) && preg_match('/^[A-Z0-9]{2}$/', $value) === 1;
        $number = fn (mixed $value): bool => is_string($value) && preg_match('/^[A-Z0-9]{1,5}$/', $value) === 1;
        if (! $code($operating) || ! $number($operatingNumber) || $departure === null || $arrival === null
            || $arrival->utcInstant <= $departure->utcInstant) {
            return null;
        }

        // A marketing reference is recorded only for a genuine codeshare (a different carrier/number than operated).
        $references = $code($marketing) && $number($marketingNumber) && $marketing.$marketingNumber !== $operating.$operatingNumber
            ? [$marketing.$marketingNumber]
            : [];

        return new FlightSegment($operating, $operatingNumber, $references, 'UNKNOWN', $departure, $arrival);
    }

    private function segmentTime(mixed $local, mixed $zone): ?SegmentTime
    {
        if (! is_string($local) || ! is_string($zone) || ! in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            return null;
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', strlen($local) === 16 ? $local.':00' : $local, new DateTimeZone($zone));
        if ($instant === false) {
            return null;
        }

        return new SegmentTime($instant->format('Y-m-d\TH:i:s'), $zone, $instant->setTimezone(new DateTimeZone('UTC')));
    }

    private function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Decimal string -> integer minor units with no floating point. Extra fractional digits must be zero.
     */
    private function money(mixed $amount, mixed $currency): ?Money
    {
        if (! is_string($amount) || ! is_string($currency) || preg_match('/^\d{1,12}(\.\d{1,6})?$/', $amount) !== 1
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }
        $exponent = match (true) {
            in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true) => 0,
            in_array($currency, self::THREE_DECIMAL_CURRENCIES, true) => 3,
            default => 2,
        };
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        if (strlen($fraction) > $exponent) {
            if (trim(substr($fraction, $exponent), '0') !== '') {
                return null;
            }
            $fraction = substr($fraction, 0, $exponent);
        }

        return new Money((int) ($whole.str_pad($fraction, $exponent, '0')), $currency);
    }

    /**
     * Included baggage as stated by the provider for the first passenger on every segment. A bag is "included" only
     * when every segment lists it; absence is never read as "not included" (unknown stays unknown). Duffel has no
     * personal-item concept, and search results carry no baggage fees.
     *
     * @param  list<mixed>  $rawSegments
     */
    private function baggage(array $rawSegments): BaggageAllowance
    {
        $included = ['checked' => true, 'carry_on' => true];
        foreach ($rawSegments as $segment) {
            $bags = is_array($segment['passengers'][0]['baggages'] ?? null) ? $segment['passengers'][0]['baggages'] : [];
            foreach (array_keys($included) as $type) {
                $has = false;
                foreach ($bags as $bag) {
                    if (is_array($bag) && ($bag['type'] ?? null) === $type && is_int($bag['quantity'] ?? null) && $bag['quantity'] > 0) {
                        $has = true;
                    }
                }
                $included[$type] = $included[$type] && $has;
            }
        }

        return new BaggageAllowance(
            personalItem: null,
            cabinBag: $included['carry_on'] ? true : null,
            checkedBag: $included['checked'] ? true : null,
        );
    }
}
