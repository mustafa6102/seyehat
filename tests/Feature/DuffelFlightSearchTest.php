<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\FlightSearch\Application\FlightSearchDispatcher;
use App\Modules\FlightSearch\Application\FlightSearchService;
use App\Modules\FlightSearch\Application\UnsupportedFlightSearchLocation;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use App\Modules\FlightSearch\Infrastructure\Duffel\DuffelFlightProviderAdapter;
use App\Modules\FlightSearch\Infrastructure\Queue\RunFlightSearch;
use App\Modules\ProviderAcl\Application\CircuitBreaker;
use App\Modules\ProviderAcl\Application\ProviderCapabilityMatrixService;
use App\Modules\ProviderAcl\Application\ResilientProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ProviderFailureKind;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use App\Modules\ProviderAcl\Domain\ProviderCapability;
use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fakes\InMemoryFlightOfferRepository;
use Tests\Fakes\InMemoryFlightSearchJobRepository;
use Tests\Fakes\InMemoryProviderCapabilityRepository;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\Fakes\ServiceOwnedTripRequests;
use Tests\TestCase;

final class DuffelFlightSearchTest extends TestCase
{
    private const TOKEN = 'duffel_flights_secret_token';

    /** @var list<array{0: string, 1: array<array-key, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Flight flow, not authorization: the caller owns every TripRequest the bound service knows (v3.16 bearerAuth).
        $this->app->bind(TripRequestOwnership::class, ServiceOwnedTripRequests::class);
        $this->withToken(FixedBearerAuthenticator::TOKEN);

        Sleep::fake();
        config([
            'services.duffel.access_token' => self::TOKEN,
            'services.duffel.flights.passenger_policy' => 'ALL_ADULT',
            'services.duffel.flights.max_offers' => 20,
            // Test-only operational values; the deploy-time profile is checked separately.
            'provider_resilience.providers.duffel_flights' => [
                'version' => 'test-1',
                'timeout_ms' => 30000,
                'retry' => ['max_attempts' => 2, 'base_delay_ms' => 10, 'max_delay_ms' => 20],
                'circuit_breaker' => ['failure_threshold' => 3, 'open_seconds' => 30],
                'rate_limit' => ['max_requests' => 100, 'per_seconds' => 60],
            ],
        ]);
        $this->app->bind(ProviderCallGuard::class, fn ($app): ProviderCallGuard => new ResilientProviderCallGuard(
            $app->make(ResiliencePolicies::class),
            $app->make(CircuitBreaker::class),
            $app->make(RateLimiter::class),
            fn (int $ceilingMs): int => 0,
        ));
        $capabilities = new InMemoryProviderCapabilityRepository;
        $capabilities->save(new ProviderCapability('duffel_flights', 'search', 'production', true, 'FRESH', 'test evidence', new DateTimeImmutable('2026-09-29T10:00:00+00:00')));
        $this->app->instance(ProviderCapabilityMatrix::class, new ProviderCapabilityMatrixService($capabilities));

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = [$event->message, $event->context];
        });
    }

    #[Test]
    public function canonical_criteria_become_the_documented_duffel_request_with_credentials_only_in_the_header(): void
    {
        Http::fake(['api.duffel.com/air/offer_requests*' => Http::response($this->body([$this->offer('off_1')]))]);

        $this->executor()->execute($this->criteria(travelers: 2), fn () => null);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data()['data'];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://api.duffel.com/air/offer_requests?')
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->hasHeader('Duffel-Version', 'v2')
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json')
                && $query === ['return_offers' => 'true', 'supplier_timeout' => '25000']
                && $data === [
                    'slices' => [['origin' => 'IST', 'destination' => 'LHR', 'departure_date' => '2026-12-15']],
                    'passengers' => [['type' => 'adult'], ['type' => 'adult']],
                ]
                && ! str_contains($request->body(), self::TOKEN);
        });
        Http::assertSentCount(1);
    }

    #[Test]
    public function city_and_airport_codes_are_sent_exactly_as_the_canonical_aviation_identity_says(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response($this->body([]))]);

        $this->executor()->execute($this->criteria(origin: ['city', 'IATA_CITY', 'LON'], destination: ['airport', 'IATA_AIRPORT', 'JFK']), fn () => null);

        Http::assertSent(fn (Request $request): bool => $request->data()['data']['slices'][0]['origin'] === 'LON'
            && $request->data()['data']['slices'][0]['destination'] === 'JFK');
    }

    #[Test]
    public function offers_are_normalized_to_canonical_fields_without_provider_payload(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response($this->body([
            $this->offer('off_1', '345.60', 'GBP'),
            $this->offer('off_2', '1200', 'JPY'),
        ]))]);

        $outcome = $this->executor()->execute($this->criteria(), fn () => null);
        $contracts = array_map(fn ($offer): array => $offer->toContract(), $outcome->offers);

        self::assertCount(2, $contracts);
        $byId = array_column($contracts, null, 'provider_offer_id');
        $first = $byId['off_1'];
        self::assertSame(['minor_units' => 34560, 'currency' => 'GBP'], $first['price']['value']);
        self::assertSame('COMPLETE', $first['price']['completeness']);
        self::assertSame('EXACT', $first['price']['certainty']);
        self::assertSame(['minor_units' => 1200, 'currency' => 'JPY'], $byId['off_2']['price']['value']);
        self::assertSame('2026-12-01T10:00:00+00:00', $first['expires_at']);
        self::assertFalse($first['bookable']);

        $segment = $first['segments'][0];
        self::assertSame('TK', $segment['operating_carrier']);
        self::assertSame('1971', $segment['operating_flight_number']);
        self::assertSame('UNKNOWN', $segment['carrier_model']);
        self::assertSame(['BA9001'], $segment['marketing_flight_references']);
        self::assertSame(['local_datetime' => '2026-12-15T08:30:00', 'iana_timezone' => 'Europe/Istanbul', 'utc_instant' => '2026-12-15T05:30:00+00:00'], $segment['departure']);
        self::assertSame(['local_datetime' => '2026-12-15T10:45:00', 'iana_timezone' => 'Europe/London', 'utc_instant' => '2026-12-15T10:45:00+00:00'], $segment['arrival']);
        self::assertSame(315, $segment['duration_minutes']);
        self::assertSame('duffel_flights', $first['sources'][0]['provider_id']);
        self::assertSame('AGGREGATOR', $first['sources'][0]['provider_role']);

        // No Duffel structure reaches the canonical response.
        $json = (string) json_encode($contracts);
        foreach (['total_amount', 'total_currency', 'owner', 'offer_request', 'orq_', 'Bearer', self::TOKEN] as $raw) {
            self::assertStringNotContainsString($raw, $json);
        }
    }

    #[Test]
    public function baggage_is_known_only_where_the_provider_lists_it_and_stays_unknown_otherwise(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response($this->body([
            $this->offer('off_bags', baggages: [['type' => 'checked', 'quantity' => 1], ['type' => 'carry_on', 'quantity' => 1]]),
            $this->offer('off_none', baggages: []),
        ]))]);

        $offers = array_column(array_map(fn ($offer): array => $offer->toContract(), $this->executor()->execute($this->criteria(), fn () => null)->offers), null, 'provider_offer_id');

        self::assertTrue($offers['off_bags']['baggage']['checked_baggage']['value']);
        self::assertTrue($offers['off_bags']['baggage']['cabin_baggage']['value']);
        self::assertSame('UNKNOWN', $offers['off_bags']['baggage']['personal_item']['certainty']);
        self::assertSame('UNKNOWN', $offers['off_none']['baggage']['checked_baggage']['certainty']);
        self::assertNull($offers['off_none']['baggage']['checked_baggage']['value']);
        self::assertSame([], $offers['off_none']['baggage']['fees']);
    }

    #[Test]
    public function incomplete_offers_are_dropped_and_the_offer_limit_is_respected(): void
    {
        config(['services.duffel.flights.max_offers' => 2]);
        $broken = [
            'string',
            array_merge($this->offer('off_amount'), ['total_amount' => '12.345']),
            array_merge($this->offer('off_currency'), ['total_currency' => 'gbp']),
            array_merge($this->offer('off_id'), ['id' => 'bad id!']),
            $this->offer('off_tz', tz: 'Mars/Base'),
            $this->offer('off_carrier', operatingNumber: null),
            array_merge($this->offer('off_slices'), ['slices' => []]),
        ];
        Http::fake(['api.duffel.com/*' => Http::response($this->body([...$broken, $this->offer('off_a', number: '1971'), $this->offer('off_b', number: '1972'), $this->offer('off_c', number: '1973')]))]);

        $ids = array_map(fn ($offer): string => $offer->providerOfferId, $this->executor()->execute($this->criteria(), fn () => null)->offers);
        sort($ids);

        self::assertSame(['off_a', 'off_b'], $ids);
    }

    #[Test]
    public function criteria_the_contract_cannot_express_never_reach_the_provider(): void
    {
        Http::fake();
        $executor = $this->executor();

        // A location without a catalog-issued aviation identity is stopped by the location gate before any adapter.
        try {
            $executor->execute($this->criteria(origin: ['city', 'IATA_CITY', null]), fn () => null);
            self::fail('The location gate must reject a place without aviation identity.');
        } catch (UnsupportedFlightSearchLocation) {
            // expected
        }

        foreach ([
            $this->criteria(journey: 'ROUND_TRIP', returnDate: ['type' => 'FLEXIBLE', 'start_date' => '2026-12-20', 'end_date' => '2026-12-22']),
            $this->criteria(outbound: ['type' => 'FLEXIBLE', 'start_date' => '2026-12-15', 'end_date' => '2026-12-18']),
            $this->criteria(travelers: 10),
        ] as $criteria) {
            $outcome = $executor->execute($criteria, fn () => null);
            self::assertSame([ProviderFailureKind::UNSUPPORTED_CRITERIA], $outcome->failures);
            self::assertStringContainsString('ölçütleri şu anda desteklenmiyor', $outcome->failedMessageTr());
            self::assertStringContainsString('esnek tarih, 9 kişiden fazla yolcu ve çocuk ya da bebek yolcu', $outcome->failedMessageTr());
            self::assertStringNotContainsString('gidiş-dönüş', $outcome->failedMessageTr());
            // A deterministic rejection is not presented as a transient failure.
            self::assertStringNotContainsString('daha sonra yeniden deneyin', $outcome->failedMessageTr());
            self::assertSame([], $outcome->offers);
        }
        Http::assertNothingSent();
    }

    /**
     * AC-FLT-002-07..09, AC-FLT-005-04: a city-coded round trip accepts only two-slice offers whose slices start and end
     * in the requested cities, in order; one-slice offers are never paired into a round trip; a bag listed on only one
     * direction stays unknown for the whole offer.
     */
    #[Test]
    public function round_trip_offers_keep_both_directions_and_mismatching_slices_are_dropped(): void
    {
        $slice = fn (string $number, string $from, string $to, ?array $bags = null): array => ['segments' => [[
            'id' => 'seg_'.$number,
            'marketing_carrier' => ['iata_code' => 'BA'], 'marketing_carrier_flight_number' => $number,
            'operating_carrier' => ['iata_code' => 'BA'], 'operating_carrier_flight_number' => $number,
            'departing_at' => $from === 'LHR' ? '2026-12-15T08:30:00' : '2026-12-20T09:00:00',
            'arriving_at' => $from === 'LHR' ? '2026-12-15T11:30:00' : '2026-12-20T21:00:00',
            'origin' => ['iata_code' => $from, 'iata_city_code' => $from === 'LHR' ? 'LON' : 'NYC', 'time_zone' => $from === 'LHR' ? 'Europe/London' : 'America/New_York'],
            'destination' => ['iata_code' => $to, 'iata_city_code' => $to === 'LHR' ? 'LON' : 'NYC', 'time_zone' => $to === 'LHR' ? 'Europe/London' : 'America/New_York'],
            'passengers' => [['passenger_id' => 'pas_1', 'baggages' => $bags ?? [['type' => 'checked', 'quantity' => 1], ['type' => 'carry_on', 'quantity' => 1]]]],
        ]]];
        $offer = fn (string $id, array $slices): array => ['id' => $id, 'total_amount' => '900.00', 'total_currency' => 'GBP', 'expires_at' => '2026-12-01T10:00:00Z', 'slices' => $slices];
        Http::fake(['api.duffel.com/*' => Http::response($this->body([
            $offer('off_rt', [$slice('1', 'LHR', 'JFK'), $slice('2', 'JFK', 'LHR', [['type' => 'carry_on', 'quantity' => 1]])]),
            $offer('off_out_only', [$slice('3', 'LHR', 'JFK')]),
            $offer('off_in_only', [$slice('4', 'JFK', 'LHR')]),
            $offer('off_swapped', [$slice('5', 'JFK', 'LHR'), $slice('6', 'LHR', 'JFK')]),
            $offer('off_three', [$slice('7', 'LHR', 'JFK'), $slice('8', 'JFK', 'LHR'), $slice('9', 'LHR', 'JFK')]),
        ]))]);

        $outcome = $this->executor()->execute($this->criteria(origin: ['city', 'IATA_CITY', 'LON'], destination: ['city', 'IATA_CITY', 'NYC'], journey: 'ROUND_TRIP'), fn () => null);

        self::assertSame(['off_rt'], array_map(fn ($o): string => $o->providerOfferId, $outcome->offers));
        $contract = $outcome->offers[0]->toContract();
        self::assertSame('ROUND_TRIP', $contract['journey_type']);
        self::assertSame(['OUTBOUND', 'INBOUND'], array_column($contract['segments'], 'direction'));
        self::assertTrue($contract['baggage']['cabin_baggage']['value']);
        self::assertSame('UNKNOWN', $contract['baggage']['checked_baggage']['certainty']);
        self::assertNull($contract['baggage']['checked_baggage']['value']);
        Http::assertSent(fn (Request $request): bool => $request->data()['data']['slices'] === [
            ['origin' => 'LON', 'destination' => 'NYC', 'departure_date' => '2026-12-15'],
            ['origin' => 'NYC', 'destination' => 'LON', 'departure_date' => '2026-12-20'],
        ]);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_one_way_search_drops_multi_slice_offers_and_keeps_its_one_way_shape(): void
    {
        $twoSlice = $this->offer('off_two');
        $twoSlice['slices'][] = $twoSlice['slices'][0];
        Http::fake(['api.duffel.com/*' => Http::response($this->body([$this->offer('off_one'), $twoSlice]))]);

        $offers = $this->executor()->execute($this->criteria(), fn () => null)->offers;

        self::assertSame(['off_one'], array_map(fn ($o): string => $o->providerOfferId, $offers));
        self::assertSame('ONE_WAY', $offers[0]->toContract()['journey_type']);
        self::assertSame(['OUTBOUND'], array_column($offers[0]->toContract()['segments'], 'direction'));
    }

    #[Test]
    public function unsupported_criteria_neither_consume_rate_slots_nor_open_the_circuit_for_valid_searches(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response($this->body([$this->offer('off_ok')]))]);
        $executor = $this->executor();

        for ($i = 0; $i < 10; $i++) {
            $executor->execute($this->criteria(travelers: 10), fn () => null);
        }
        $valid = $executor->execute($this->criteria(), fn () => null);

        self::assertSame(['off_ok'], array_map(fn ($offer): string => $offer->providerOfferId, $valid->offers));
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_credential_registers_the_adapter_but_legacy_count_only_searches_still_require_the_explicit_policy(): void
    {
        Http::fake();

        config(['services.duffel.access_token' => '']);
        self::assertFalse(DuffelFlightProviderAdapter::isConfigured($this->app->make('config')));
        self::assertFalse($this->app->make(FlightSearchExecutor::class)->isAvailable());

        config(['services.duffel.access_token' => self::TOKEN, 'services.duffel.flights.passenger_policy' => null]);
        self::assertTrue(DuffelFlightProviderAdapter::isConfigured($this->app->make('config')));
        self::assertTrue($this->app->make(FlightSearchExecutor::class)->isAvailable());
        $outcome = $this->executor()->execute($this->criteria(), fn () => null);
        self::assertSame([ProviderFailureKind::UNSUPPORTED_CRITERIA], $outcome->failures);

        config(['services.duffel.flights.passenger_policy' => 'ALL_ADULT']);
        self::assertTrue($this->app->make(FlightSearchExecutor::class)->isAvailable());
        $this->get('/')->assertOk();
        Http::assertNothingSent();
    }

    #[Test]
    public function explicit_adult_composition_maps_to_duffel_without_the_legacy_policy(): void
    {
        config(['services.duffel.access_token' => self::TOKEN, 'services.duffel.flights.passenger_policy' => null]);
        Http::fake(['api.duffel.com/*' => Http::response($this->body([$this->offer('off_adults')]), 200)]);

        $criteria = FlightSearchCriteria::fromSearchableTripRequest(
            'trip-duffel',
            $this->tripView(travelers: 2, passengerComposition: ['adults' => 2, 'children' => 0, 'infants' => 0]),
        );
        $offers = $this->executor()->execute($criteria, fn () => null)->offers;

        self::assertCount(1, $offers);
        Http::assertSent(fn (Request $request): bool => $request->data()['data']['passengers'] === [['type' => 'adult'], ['type' => 'adult']]);
    }

    #[Test]
    public function child_or_infant_composition_is_rejected_before_provider_http(): void
    {
        config(['services.duffel.access_token' => self::TOKEN, 'services.duffel.flights.passenger_policy' => null]);
        Http::fake();

        foreach ([
            ['adults' => 1, 'children' => 1, 'infants' => 0],
            ['adults' => 1, 'children' => 0, 'infants' => 1],
        ] as $composition) {
            $criteria = FlightSearchCriteria::fromSearchableTripRequest(
                'trip-duffel',
                $this->tripView(travelers: 2, passengerComposition: $composition),
            );
            self::assertFalse($this->app->make(DuffelFlightProviderAdapter::class)->supportsCriteria($criteria));
            $outcome = $this->executor()->execute($criteria, fn () => null);
            self::assertSame([ProviderFailureKind::UNSUPPORTED_CRITERIA], $outcome->failures);
            self::assertStringContainsString('desteklenmiyor', $outcome->failedMessageTr());
        }

        config(['services.duffel.flights.passenger_policy' => 'ALL_ADULT']);
        $this->freshHttp();
        Http::fake(['api.duffel.com/*' => Http::response($this->body([$this->offer('off_ok')]))]);
        $valid = $this->executor()->execute($this->criteria(), fn () => null);
        self::assertSame(['off_ok'], array_map(fn ($offer): string => $offer->providerOfferId, $valid->offers));
        Http::assertSentCount(1);
    }

    #[Test]
    public function provider_failures_map_to_canonical_kinds_without_leaking_provider_text_or_the_token(): void
    {
        $cases = [
            [401, ProviderFailureKind::NOT_CONFIGURED],
            [403, ProviderFailureKind::NOT_CONFIGURED],
            [400, ProviderFailureKind::NOT_CONFIGURED],
            [422, ProviderFailureKind::NOT_CONFIGURED],
            [429, ProviderFailureKind::RATE_LIMITED],
            [500, ProviderFailureKind::UNAVAILABLE],
            [503, ProviderFailureKind::UNAVAILABLE],
            [504, ProviderFailureKind::TIMEOUT],
        ];
        foreach ($cases as [$status, $kind]) {
            $this->freshHttp();
            Http::fake(['api.duffel.com/*' => Http::response(['errors' => [['type' => 'validation_error', 'code' => 'bad_field', 'message' => 'SECRET provider text '.self::TOKEN]]], $status)]);

            $outcome = $this->executor()->execute($this->criteria(), fn () => null);

            self::assertSame([$kind], $outcome->failures, "HTTP {$status}");
            self::assertStringNotContainsString('SECRET', $outcome->failedMessageTr());
        }

        $rejected = array_values(array_filter($this->logged, fn (array $entry): bool => $entry[0] === 'provider.duffel_flights.rejected'));
        self::assertNotEmpty($rejected);
        self::assertSame('validation_error', $rejected[0][1]['error_type']);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($this->logged));
        self::assertStringNotContainsString('SECRET', (string) json_encode($this->logged));
    }

    #[Test]
    public function timeout_and_transport_failures_are_isolated_and_retried_through_the_guard(): void
    {
        Http::fake(['api.duffel.com/*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out')
            ->push($this->body([$this->offer('off_retry')]))]);

        $outcome = $this->executor()->execute($this->criteria(), fn () => null);

        // One retry inside ProviderCallGuard (max_attempts 2 in this test profile); the adapter has no loop of its own.
        self::assertSame(['off_retry'], array_map(fn ($offer): string => $offer->providerOfferId, $outcome->offers));
        Http::assertSentCount(2);

        $this->freshHttp();
        Http::fake(['api.duffel.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        self::assertSame([ProviderFailureKind::TIMEOUT], $this->executor()->execute($this->criteria(), fn () => null)->failures);
    }

    #[Test]
    public function malformed_bodies_are_a_safe_provider_failure(): void
    {
        foreach ([['unexpected' => true], ['data' => ['offers' => 'x']], ['data' => ['offers' => ['a' => 1]]]] as $body) {
            $this->freshHttp();
            Http::fake(['api.duffel.com/*' => Http::response($body)]);
            self::assertSame([ProviderFailureKind::UNAVAILABLE], $this->executor()->execute($this->criteria(), fn () => null)->failures);
        }
    }

    #[Test]
    public function the_circuit_opens_after_repeated_failures_and_stops_calling_the_provider(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response('down', 503)]);
        $executor = $this->executor();

        for ($i = 0; $i < 4; $i++) {
            $executor->execute($this->criteria(), fn () => null);
        }
        $sent = count(Http::recorded());
        $blocked = $executor->execute($this->criteria(), fn () => null);

        self::assertSame($sent, count(Http::recorded()));
        self::assertTrue($blocked->allProvidersFailed());
        self::assertSame(ProviderFailureKind::CIRCUIT_OPEN, $blocked->failures[0]);
    }

    #[Test]
    public function missing_search_capability_evidence_makes_search_unavailable_without_a_provider_call(): void
    {
        $this->app->instance(ProviderCapabilityMatrix::class, new ProviderCapabilityMatrixService(new InMemoryProviderCapabilityRepository));
        Http::fake();

        $this->app->forgetInstance(FlightSearchExecutor::class);
        self::assertFalse($this->app->make(FlightSearchExecutor::class)->isAvailable());
        Http::assertNothingSent();
    }

    #[Test]
    public function the_deploy_profile_is_complete_and_stays_below_the_documented_rate_limit(): void
    {
        $profile = (require config_path('provider_resilience.php'))['providers']['duffel_flights'];

        self::assertSame(30000, $profile['timeout_ms']);
        self::assertSame(1, $profile['retry']['max_attempts']);
        self::assertLessThan(60, $profile['rate_limit']['max_requests'] * 60 / $profile['rate_limit']['per_seconds']);
    }

    #[Test]
    public function an_async_search_job_runs_the_real_adapter_and_polling_returns_only_canonical_offers(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response($this->body([$this->offer('off_1'), $this->offer('off_2', '500.00', 'GBP')]))]);
        $jobs = new InMemoryFlightSearchJobRepository;
        $tripRequests = $this->tripRequests();
        $this->app->instance(FlightSearchJobRepository::class, $jobs);
        $this->app->instance(TripRequestService::class, $tripRequests);

        $service = new FlightSearchService($jobs, $this->dispatcher());
        $started = $service->start('duffel-key-1', 'trip-duffel');
        $again = $service->start('duffel-key-1', 'trip-duffel');
        self::assertSame($started->searchId, $again->searchId);

        (new RunFlightSearch($started->searchId))->handle($jobs, $this->executor(), new InMemoryFlightOfferRepository, $tripRequests);

        $this->getJson('/api/v1/flight-search-jobs/'.$started->searchId)
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonCount(2, 'result.offers')
            ->assertJsonPath('result.offers.0.price.completeness', 'COMPLETE');
        $response = (string) $this->getJson('/api/v1/flight-search-jobs/'.$started->searchId)->getContent();
        self::assertStringNotContainsString(self::TOKEN, $response);
        self::assertStringNotContainsString('total_amount', $response);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_provider_failure_fails_the_async_job_with_a_turkish_message(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response('down', 503)]);
        $jobs = new InMemoryFlightSearchJobRepository;
        $tripRequests = $this->tripRequests();
        $started = (new FlightSearchService($jobs, $this->dispatcher()))->start('duffel-key-2', 'trip-duffel');

        (new RunFlightSearch($started->searchId))->handle($jobs, $this->executor(), new InMemoryFlightOfferRepository, $tripRequests);

        $record = $jobs->find($started->searchId);
        self::assertSame('FAILED', $record?->status);
        self::assertStringContainsString('Sağlayıcıya şu anda ulaşılamıyor.', (string) $record?->errorMessageTr);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($record));
    }

    private function executor(): FlightSearchExecutor
    {
        return $this->app->make(FlightSearchExecutor::class);
    }

    private function dispatcher(): FlightSearchDispatcher
    {
        return new class implements FlightSearchDispatcher
        {
            public function dispatch(string $searchId): void {}
        };
    }

    /** A new HTTP factory (stubs from earlier fakes must not win) and a closed circuit for the next scenario. */
    private function freshHttp(): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        $this->app->make('cache.store')->flush();
    }

    private function tripRequests(): TripRequestService
    {
        $view = $this->tripView();

        return new class($view) implements TripRequestService
        {
            public function __construct(private readonly TripRequestView $view) {}

            public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
            {
                throw new RuntimeException('Not used.');
            }

            public function get(string $id): ?TripRequestView
            {
                return $id === 'trip-duffel' ? $this->view : null;
            }

            public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
            {
                return [];
            }
        };
    }

    /**
     * @param  array{0: string, 1: string, 2: ?string}  $origin
     * @param  array{0: string, 1: string, 2: ?string}  $destination
     * @param  array<string, string>|null  $outbound
     */
    private function tripView(array $origin = ['airport', 'IATA_AIRPORT', 'IST'], array $destination = ['airport', 'IATA_AIRPORT', 'LHR'], string $journey = 'ONE_WAY', ?array $outbound = null, int $travelers = 1, ?array $passengerComposition = null, ?array $returnDate = null): TripRequestView
    {
        $place = fn (array $spec, string $tz): array => ['kind' => $spec[0], 'value' => 'duffel:x_'.strtolower($spec[2] ?? 'none'), 'iana_timezone' => $tz]
            + ($spec[2] === null ? [] : ['aviation_identity' => ['type' => $spec[1], 'code' => $spec[2]]]);

        return new TripRequestView(
            status: 'SEARCHABLE',
            originPlace: $place($origin, 'Europe/Istanbul'),
            destinationPlace: $place($destination, 'Europe/London'),
            dateWindow: null,
            travelerCount: $travelers,
            preferences: null,
            journeyType: $journey,
            outboundDate: $outbound ?? ['type' => 'EXACT', 'date' => '2026-12-15'],
            returnDate: $journey === 'ROUND_TRIP' ? ($returnDate ?? ['type' => 'EXACT', 'date' => '2026-12-20']) : null,
            passengerComposition: $passengerComposition,
        );
    }

    /**
     * @param  array{0: string, 1: string, 2: ?string}  $origin
     * @param  array{0: string, 1: string, 2: ?string}  $destination
     * @param  array<string, string>|null  $outbound
     */
    private function criteria(array $origin = ['airport', 'IATA_AIRPORT', 'IST'], array $destination = ['airport', 'IATA_AIRPORT', 'LHR'], string $journey = 'ONE_WAY', ?array $outbound = null, int $travelers = 1, ?array $returnDate = null): FlightSearchCriteria
    {
        return FlightSearchCriteria::fromSearchableTripRequest('trip-duffel', $this->tripView($origin, $destination, $journey, $outbound, $travelers, returnDate: $returnDate));
    }

    /**
     * @param  list<mixed>  $offers
     * @return array<string, mixed>
     */
    private function body(array $offers): array
    {
        return ['data' => ['id' => 'orq_0001', 'live_mode' => false, 'offers' => $offers]];
    }

    /**
     * @param  list<array<string, mixed>>|null  $baggages
     * @return array<string, mixed>
     */
    private function offer(string $id, string $amount = '345.60', string $currency = 'GBP', ?array $baggages = null, string $tz = 'Europe/Istanbul', ?string $operatingNumber = '1971', ?string $number = null): array
    {
        return [
            'id' => $id,
            'total_amount' => $amount,
            'total_currency' => $currency,
            'expires_at' => '2026-12-01T10:00:00Z',
            'owner' => ['iata_code' => 'TK', 'name' => 'Turkish Airlines'],
            'slices' => [[
                'segments' => [[
                    'id' => 'seg_1',
                    'marketing_carrier' => ['iata_code' => 'BA'],
                    'marketing_carrier_flight_number' => '9001',
                    'operating_carrier' => ['iata_code' => 'TK'],
                    'operating_carrier_flight_number' => $number ?? $operatingNumber,
                    'departing_at' => '2026-12-15T08:30:00',
                    'arriving_at' => '2026-12-15T10:45:00',
                    'origin' => ['iata_code' => 'IST', 'time_zone' => $tz],
                    'destination' => ['iata_code' => 'LHR', 'time_zone' => 'Europe/London'],
                    'passengers' => [['passenger_id' => 'pas_1', 'baggages' => $baggages ?? [['type' => 'checked', 'quantity' => 1]]]],
                ]],
            ]],
        ];
    }
}
