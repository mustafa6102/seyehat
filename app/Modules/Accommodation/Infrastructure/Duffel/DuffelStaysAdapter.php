<?php

namespace App\Modules\Accommodation\Infrastructure\Duffel;

use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderFailure;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * duffel_stays accommodation_search (OD-023): POST /stays/search, then POST /stays/search_results/{id}/actions/
 * fetch_all_rates per result, through the provider's resilience guard. Only SCH-AccommodationOffer v3.14 arrays leave
 * this class; raw responses are neither stored nor logged. Quotes, bookings and cancellations are out of scope.
 */
final readonly class DuffelStaysAdapter
{
    public const PROVIDER_ID = 'duffel_stays';

    private const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public function __construct(private Factory $http, private ProviderCallGuard $guard) {}

    public static function isConfigured(): bool
    {
        return trim((string) config('services.duffel.access_token')) !== '';
    }

    /** CFG-ACCOMMODATION-SEARCH-MAX-PROPERTIES, or null while it is not configured (value OPEN). */
    public static function maxProperties(): ?int
    {
        $value = config('services.duffel.stays.max_properties');

        return is_int($value) && $value >= 1 ? $value : null;
    }

    /**
     * duffel_stays accommodation_revalidate (OD-023): POST /stays/quotes with the stored rate id. The quote's amounts and
     * the quoted rate are mapped with the same canonical rules as search; the quote id is never an offer identity.
     *
     * @param  array<string, mixed>  $offer  stored SCH-AccommodationOffer
     * @return array<string, mixed> SCH-AccommodationOffer (state/verification are set by the caller)
     *
     * @throws ProviderFailure
     */
    public function quote(array $offer, DateTimeImmutable $verifiedAt): array
    {
        if (! self::isConfigured()) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }
        $rateId = (string) ($offer['source']['provider_offer_id'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $rateId) !== 1) {
            throw ProviderFailure::unavailable();
        }
        $base = rtrim((string) config('services.duffel.base_url'), '/');
        $body = $this->guard->call(self::PROVIDER_ID, fn (int $timeoutMs): array => $this->send($base.'/stays/quotes', $timeoutMs, ['data' => ['rate_id' => $rateId]]));
        $quote = is_array($body['data'] ?? null) ? $body['data'] : null;
        $accommodation = is_array($quote['accommodation'] ?? null) ? $quote['accommodation'] : null;
        $context = $accommodation === null ? null : $this->context($accommodation);
        if ($quote === null || $context === null) {
            throw ProviderFailure::unavailable();
        }

        // The quoted rate inside the quote's accommodation; the quote's own totals are authoritative for the price.
        [$room, $rate] = [null, []];
        foreach (is_array($accommodation['rooms'] ?? null) ? $accommodation['rooms'] : [] as $candidateRoom) {
            foreach (is_array($candidateRoom['rates'] ?? null) ? $candidateRoom['rates'] : [] as $candidateRate) {
                if (is_array($candidateRate) && ($candidateRate['id'] ?? null) === $rateId) {
                    [$room, $rate] = [$candidateRoom, $candidateRate];
                }
            }
        }
        $rate = array_merge($rate, [
            'id' => $rateId,
            'total_amount' => $quote['total_amount'] ?? null,
            'total_currency' => $quote['total_currency'] ?? null,
            'due_at_accommodation_amount' => $quote['due_at_accommodation_amount'] ?? null,
            'due_at_accommodation_currency' => $quote['due_at_accommodation_currency'] ?? null,
            // A quote has no expires_at; the last verified rate expiry applies (OD-023).
            'expires_at' => is_string($rate['expires_at'] ?? null) ? $rate['expires_at'] : ($offer['expires_at'] ?? null),
        ]);

        return $this->offer($rate, $room, $context, $quote, ['check_in_date' => $offer['check_in_date'], 'check_out_date' => $offer['check_out_date']], $verifiedAt)
            ?? throw ProviderFailure::unavailable();
    }

    /**
     * @param  array<string, mixed>  $criteria  SCH-AccommodationSearchCriteria
     * @return list<array<string, mixed>> SCH-AccommodationOffer
     *
     * @throws ProviderFailure
     */
    public function search(array $criteria): array
    {
        if (! self::isConfigured()) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }
        $base = rtrim((string) config('services.duffel.base_url'), '/');
        $payload = ['data' => [
            'rooms' => $criteria['rooms'],
            'guests' => array_fill(0, $criteria['adults'], ['type' => 'adult']),
            'check_in_date' => $criteria['check_in_date'],
            'check_out_date' => $criteria['check_out_date'],
            'location' => [
                'radius' => $criteria['search_radius_km'],
                'geographic_coordinates' => $criteria['destination_coordinates'],
            ],
        ]];
        $search = $this->guard->call(self::PROVIDER_ID, fn (int $timeoutMs): array => $this->send($base.'/stays/search', $timeoutMs, $payload));
        $results = $search['data']['results'] ?? null;
        if (! is_array($results) || ! array_is_list($results)) {
            throw ProviderFailure::unavailable();
        }

        $offers = [];
        // CFG-ACCOMMODATION-SEARCH-MAX-PROPERTIES is OPEN (ADR-006): no value is substituted when it is not configured.
        $maxProperties = self::maxProperties();
        if ($maxProperties === null) {
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }
        foreach (array_slice($results, 0, $maxProperties) as $result) {
            $resultId = is_array($result) ? ($result['id'] ?? null) : null;
            if (! is_string($resultId) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $resultId) !== 1) {
                continue;
            }
            $rates = $this->guard->call(self::PROVIDER_ID, fn (int $timeoutMs): array => $this->send($base.'/stays/search_results/'.$resultId.'/actions/fetch_all_rates', $timeoutMs, []));
            $data = is_array($rates['data'] ?? null) ? $rates['data'] : [];
            $fetchedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            array_push($offers, ...$this->offers($data, $criteria, $fetchedAt));
        }

        return $offers;
    }

    /**
     * One search result with all rooms/rates -> canonical offers; a rate with a missing or malformed authoritative field
     * is dropped, never completed with a guess.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    private function offers(array $data, array $criteria, DateTimeImmutable $fetchedAt): array
    {
        $accommodation = is_array($data['accommodation'] ?? null) ? $data['accommodation'] : null;
        $context = $accommodation === null ? null : $this->context($accommodation);
        if ($context === null) {
            return [];
        }

        $offers = [];
        foreach (is_array($accommodation['rooms'] ?? null) ? $accommodation['rooms'] : [] as $room) {
            foreach (is_array($room['rates'] ?? null) ? $room['rates'] : [] as $rate) {
                $offer = is_array($rate) ? $this->offer($rate, $room, $context, $data, $criteria, $fetchedAt) : null;
                if ($offer !== null) {
                    $offers[] = $offer;
                }
            }
        }

        return $offers;
    }

    /**
     * Accommodation-level canonical fields shared by search and quote, or null without an id and name.
     *
     * @param  array<array-key, mixed>  $accommodation
     * @return array{property_id: string, name: string, address: string, coordinates: mixed, check_in: array<array-key, mixed>}|null
     */
    private function context(array $accommodation): ?array
    {
        $propertyId = $accommodation['id'] ?? null;
        $name = $accommodation['name'] ?? null;
        if (! is_string($propertyId) || $propertyId === '' || ! is_string($name) || trim($name) === '') {
            return null;
        }
        $address = is_array($accommodation['location']['address'] ?? null) ? $accommodation['location']['address'] : [];

        return [
            'property_id' => $propertyId,
            'name' => trim($name),
            'address' => implode(', ', array_filter([
                $this->text($address['line_one'] ?? null),
                trim(($this->text($address['postal_code'] ?? null) ?? '').' '.($this->text($address['city_name'] ?? null) ?? '')) ?: null,
                $this->text($address['region'] ?? null),
                $this->text($address['country_code'] ?? null),
            ])),
            'coordinates' => $accommodation['location']['geographic_coordinates'] ?? [],
            'check_in' => is_array($accommodation['check_in_information'] ?? null) ? $accommodation['check_in_information'] : [],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $rate
     * @param  array{property_id: string, name: string, address: string, coordinates: mixed, check_in: array<array-key, mixed>}  $context
     * @return array<string, mixed>|null
     */
    private function offer(array $rate, mixed $room, array $context, array $data, array $criteria, DateTimeImmutable $fetchedAt): ?array
    {
        ['property_id' => $propertyId, 'name' => $name, 'address' => $addressLine, 'coordinates' => $coordinates, 'check_in' => $checkIn] = $context;
        $rateId = $rate['id'] ?? null;
        $total = $this->money($rate['total_amount'] ?? null, $rate['total_currency'] ?? null);
        try {
            $expiresAt = is_string($rate['expires_at'] ?? null) ? new DateTimeImmutable($rate['expires_at']) : null;
        } catch (Throwable) {
            $expiresAt = null;
        }
        if (! is_string($rateId) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $rateId) !== 1 || $total === null || $expiresAt === null) {
            return null;
        }

        return [
            'offer_id' => $rateId,
            'property_id' => $propertyId,
            'property_name' => $name,
            'location' => [
                'address' => $addressLine === '' ? null : $addressLine,
                'latitude' => is_int($coordinates['latitude'] ?? null) || is_float($coordinates['latitude'] ?? null) ? (float) $coordinates['latitude'] : null,
                'longitude' => is_int($coordinates['longitude'] ?? null) || is_float($coordinates['longitude'] ?? null) ? (float) $coordinates['longitude'] : null,
            ],
            'check_in_date' => is_string($data['check_in_date'] ?? null) ? $data['check_in_date'] : $criteria['check_in_date'],
            'check_out_date' => is_string($data['check_out_date'] ?? null) ? $data['check_out_date'] : $criteria['check_out_date'],
            'room_description' => is_array($room) ? $this->text($room['name'] ?? null) : null,
            'total_price' => $this->totalPrice($rate, $total, $rateId, $fetchedAt),
            'cancellation' => $this->cancellation($rate['cancellation_timeline'] ?? null, $total),
            // OD-023: property content, not confirmed for this rate -> never VERIFIED (RULE-ACC-001-02).
            'check_in' => [
                'from_time' => $this->time($checkIn['check_in_after_time'] ?? null),
                'until_time' => $this->time($checkIn['check_in_before_time'] ?? null),
                'verification_status' => 'UNVERIFIED',
            ],
            // A search result is not a server-side revalidation; it is not current for selection until revalidated.
            'state' => $expiresAt <= $fetchedAt ? 'EXPIRED' : 'UNVERIFIED',
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'source' => [
                'provider_id' => self::PROVIDER_ID,
                'provider_offer_id' => $rateId,
                'fetched_at' => $fetchedAt->format(DATE_ATOM),
                'verified_at' => null,
            ],
        ];
    }

    /**
     * SCH-DerivedMoney per OD-023: total_amount covers what is paid at booking; due_at_accommodation null or in another
     * currency keeps the total PARTIAL (no FX, never 0). Only a known same-currency due amount makes it COMPLETE.
     *
     * @param  array<array-key, mixed>  $rate
     * @param  array{minor_units: int, currency: string}  $total
     * @return array<string, mixed>
     */
    private function totalPrice(array $rate, array $total, string $rateId, DateTimeImmutable $calculatedAt): array
    {
        $due = $this->money($rate['due_at_accommodation_amount'] ?? null, $rate['due_at_accommodation_currency'] ?? null);
        $complete = $due !== null && $due['currency'] === $total['currency'];
        $unknown = match (true) {
            $complete => [],
            $due === null => ['MANDATORY_FEES_DUE_AT_ACCOMMODATION'],
            default => ['MANDATORY_FEES_DUE_AT_ACCOMMODATION_OTHER_CURRENCY'],
        };

        return [
            'value' => $complete ? ['minor_units' => $total['minor_units'] + $due['minor_units'], 'currency' => $total['currency']] : null,
            'certainty' => $complete ? 'EXACT' : 'UNKNOWN',
            'completeness' => $complete ? 'COMPLETE' : 'PARTIAL',
            'known_lower_bound' => $complete ? null : $total,
            'unknown_components' => $unknown,
            'fx_snapshot_id' => null,
            'input_refs' => ['duffel_stays:rate:'.$rateId.':total_amount', 'duffel_stays:rate:'.$rateId.':due_at_accommodation_amount'],
            'calculation_version' => 'v3.14',
            'calculated_at' => $calculatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * OD-023: full refund entry -> REFUNDABLE (until its "before"), only partial refunds -> PARTIALLY_REFUNDABLE, only
     * zero refunds -> NON_REFUNDABLE. An empty or unreadable timeline stays UNKNOWN.
     *
     * @param  array{minor_units: int, currency: string}  $total
     * @return array<string, mixed>
     */
    private function cancellation(mixed $timeline, array $total): array
    {
        $unknown = ['policy' => 'UNKNOWN', 'free_cancellation_until' => null, 'verification_status' => 'UNVERIFIED'];
        if (! is_array($timeline) || $timeline === [] || ! array_is_list($timeline)) {
            return $unknown;
        }
        $fullUntil = null;
        $partial = false;
        foreach ($timeline as $entry) {
            $refund = is_array($entry) ? $this->money($entry['refund_amount'] ?? null, $entry['currency'] ?? null) : null;
            if ($refund === null || $refund['currency'] !== $total['currency']) {
                return $unknown;
            }
            if ($refund['minor_units'] >= $total['minor_units']) {
                try {
                    $fullUntil = is_string($entry['before'] ?? null) ? (new DateTimeImmutable($entry['before']))->format(DATE_ATOM) : $fullUntil;
                } catch (Throwable) {
                    return $unknown;
                }
            } elseif ($refund['minor_units'] > 0) {
                $partial = true;
            }
        }

        return [
            'policy' => $fullUntil !== null ? 'REFUNDABLE' : ($partial ? 'PARTIALLY_REFUNDABLE' : 'NON_REFUNDABLE'),
            'free_cancellation_until' => $fullUntil,
            'verification_status' => 'VERIFIED',
        ];
    }

    /** @return array{minor_units: int, currency: string}|null decimal string -> integer minor units without floats */
    private function money(mixed $amount, mixed $currency): ?array
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

        return ['minor_units' => (int) ($whole.str_pad($fraction, $exponent, '0')), 'currency' => $currency];
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $value) === 1 ? substr($value, 0, 5) : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * One Duffel Stays call mapped to canonical failures (same status mapping as the flight adapter, separate class so
     * flight and Stays mappings stay independent). An empty payload is sent as a body-less POST.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function send(string $url, int $timeoutMs, array $payload): array
    {
        try {
            $pending = $this->http->timeout($timeoutMs / 1000)->connectTimeout(min($timeoutMs, 10000) / 1000)
                ->withToken(trim((string) config('services.duffel.access_token')))
                ->withHeaders(['Duffel-Version' => (string) config('services.duffel.version')])
                ->acceptJson();
            $response = $payload === [] ? $pending->post($url) : $pending->asJson()->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw str_contains($exception->getMessage(), 'cURL error 28') ? ProviderFailure::timeout() : ProviderFailure::unavailable();
        }

        $status = $response->status();
        if ($status >= 400) {
            $error = is_array($response->json('errors.0')) ? $response->json('errors.0') : [];
            $safe = fn (mixed $value): ?string => is_string($value) && preg_match('/^[a-z0-9_]{1,64}$/', $value) === 1 ? $value : null;
            Log::warning('provider.duffel_stays.rejected', [
                'provider_id' => self::PROVIDER_ID,
                'http_status' => $status,
                'error_type' => $safe($error['type'] ?? null),
                'error_code' => $safe($error['code'] ?? null),
            ]);
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
            throw ProviderFailure::of(ProviderFailureKind::NOT_CONFIGURED);
        }
        $body = $response->successful() ? $response->json() : null;
        if (! is_array($body)) {
            throw ProviderFailure::unavailable();
        }

        return $body;
    }
}
