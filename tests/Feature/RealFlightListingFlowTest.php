<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Http\Support\AviationLocationSelection;
use App\Modules\Api\Application\TripRequestApplicationService;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use App\Modules\ProviderAcl\Application\ProviderCapabilityMatrixService;
use App\Modules\ProviderAcl\Contracts\AviationIdentity;
use App\Modules\ProviderAcl\Contracts\LocationOption;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Domain\ProviderCapability;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryFlightOfferRepository;
use Tests\Fakes\InMemoryFlightSearchJobRepository;
use Tests\Fakes\InMemoryProviderCapabilityRepository;
use Tests\Fakes\InMemoryTripRequestRepository;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\Fakes\ServiceOwnedTripRequests;
use Tests\TestCase;

/**
 * Trip Planner -> POST /planla -> summary -> OP-startFlightSearch -> queue worker -> Duffel (mocked HTTP)
 * -> canonical FlightOffer -> OP-pollFlightSearch. The queue runs synchronously here: it stands in for the worker.
 */
final class RealFlightListingFlowTest extends TestCase
{
    private const TOKEN = 'duffel_listing_flow_secret';

    private InMemoryFlightSearchJobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();

        // Flight flow, not authorization: the caller owns every TripRequest the bound service knows (v3.16 bearerAuth).
        $this->app->bind(TripRequestOwnership::class, ServiceOwnedTripRequests::class);
        $this->withToken(FixedBearerAuthenticator::TOKEN);

        Sleep::fake();
        config([
            'queue.default' => 'sync',
            'services.duffel.access_token' => self::TOKEN,
            'services.duffel.flights.passenger_policy' => null,
            'services.duffel.flights.max_offers' => 20,
            'provider_resilience.providers.duffel_flights' => [
                'version' => 'test-1', 'timeout_ms' => 30000,
                'retry' => ['max_attempts' => 1, 'base_delay_ms' => 10, 'max_delay_ms' => 20],
                'circuit_breaker' => ['failure_threshold' => 5, 'open_seconds' => 30],
                'rate_limit' => ['max_requests' => 100, 'per_seconds' => 60],
            ],
        ]);
        $this->useCapability(true);
        $this->jobs = new InMemoryFlightSearchJobRepository;
        $this->app->instance(FlightSearchJobRepository::class, $this->jobs);
        $this->app->instance(FlightOfferRepository::class, new InMemoryFlightOfferRepository);
        $this->app->instance(TripRequestService::class, new TripRequestApplicationService(new InMemoryTripRequestRepository));
    }

    #[Test]
    public function an_adult_one_way_exact_date_trip_lists_real_canonical_offers_through_the_worker(): void
    {
        Http::fake(['api.duffel.com/air/offer_requests*' => Http::response($this->body([
            $this->offer('off_a', '345.60', 'GBP', '1971', [['type' => 'checked', 'quantity' => 1], ['type' => 'carry_on', 'quantity' => 1]]),
            $this->offer('off_b', '412.00', 'GBP', '1973', []),
        ]))]);

        $tripId = $this->createTrip(adults: 2);
        $this->get('/planlar/'.$tripId)->assertOk()->assertSee('Uçuşları ara');
        $this->get('/planlar/'.$tripId.'/ucuslar')->assertOk()->assertSee('data-search-start', false);

        // Start -> FlightSearchJob -> worker (sync queue) -> Duffel -> canonical offers.
        $start = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => $tripId], ['Idempotency-Key' => 'listing-1'])->assertAccepted();
        $again = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => $tripId], ['Idempotency-Key' => 'listing-1'])->assertAccepted();
        self::assertSame($start->json('search_id'), $again->json('search_id'));

        $poll = $this->getJson('/api/v1/flight-search-jobs/'.$start->json('search_id'))->assertOk();
        self::assertSame('COMPLETED', $poll->json('status'));
        $offers = array_column($poll->json('result.offers'), null, 'provider_offer_id');
        self::assertCount(2, $offers);
        self::assertSame(['minor_units' => 34560, 'currency' => 'GBP'], $offers['off_a']['price']['value']);
        self::assertSame('Europe/Istanbul', $offers['off_a']['segments'][0]['departure']['iana_timezone']);
        self::assertSame('TK', $offers['off_a']['segments'][0]['operating_carrier']);

        // Known baggage is shown as known; missing provider data stays unknown (never 0, free or included).
        self::assertTrue($offers['off_a']['baggage']['checked_baggage']['value']);
        self::assertSame('UNKNOWN', $offers['off_b']['baggage']['checked_baggage']['certainty']);
        self::assertNull($offers['off_b']['baggage']['checked_baggage']['value']);
        self::assertSame([], $offers['off_b']['baggage']['fees']);

        // The provider was called exactly once, with the explicit adult composition, and nothing raw came back.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->data()['data']['passengers'] === [['type' => 'adult'], ['type' => 'adult']]
            && $request->data()['data']['slices'] === [['origin' => 'IST', 'destination' => 'LHR', 'departure_date' => '2026-12-15']]);
        $json = (string) $poll->getContent();
        foreach (['total_amount', 'total_currency', 'orq_', 'owner', self::TOKEN, 'Bearer'] as $raw) {
            self::assertStringNotContainsString($raw, $json);
        }
        $this->getJson('/api/v1/offers/off_a')->assertOk()->assertJsonPath('provider_offer_id', 'off_a');
    }

    #[Test]
    public function a_provider_failure_ends_the_job_without_any_synthetic_offer(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response(['errors' => [['type' => 'api_error', 'message' => 'raw provider text']]], 503)]);
        $tripId = $this->createTrip();

        $id = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => $tripId], ['Idempotency-Key' => 'listing-2'])->assertAccepted()->json('search_id');
        $poll = $this->getJson('/api/v1/flight-search-jobs/'.$id)->assertOk();

        self::assertSame('FAILED', $poll->json('status'));
        self::assertStringContainsString('Sağlayıcıya şu anda ulaşılamıyor.', (string) $poll->json('error.message_tr'));
        self::assertNull($poll->json('result'));
        self::assertStringNotContainsString('raw provider text', (string) $poll->getContent());
    }

    #[Test]
    public function child_or_infant_compositions_make_zero_provider_calls_and_list_nothing(): void
    {
        Http::fake();
        $tripId = $this->createTrip(adults: 1, children: 1);

        $id = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => $tripId], ['Idempotency-Key' => 'listing-3'])->assertAccepted()->json('search_id');
        $poll = $this->getJson('/api/v1/flight-search-jobs/'.$id)->assertOk();

        self::assertSame('FAILED', $poll->json('status'));
        self::assertNull($poll->json('result'));
        Http::assertNothingSent();
    }

    #[Test]
    public function without_provider_capability_the_search_page_is_unavailable_and_a_started_job_fails_cleanly(): void
    {
        Http::fake();
        $tripId = $this->createTrip();
        $this->useCapability(false);

        $this->get('/planlar/'.$tripId.'/ucuslar')->assertOk()
            ->assertSee('Uçuş araması şu anda kullanılamıyor')
            ->assertDontSee('data-search-start', false);

        // Even a direct API start never reaches a provider and never leaves a retrying job behind.
        $id = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => $tripId], ['Idempotency-Key' => 'listing-4'])->assertAccepted()->json('search_id');
        $poll = $this->getJson('/api/v1/flight-search-jobs/'.$id)->assertOk();
        self::assertSame('FAILED', $poll->json('status'));
        self::assertNull($poll->json('result'));
        Http::assertNothingSent();
    }

    /**
     * TS-FLT-002-08 (AC-FLT-002-07), TS-FLT-002-10 (AC-FLT-002-09), TS-FLT-002-11 (AC-FLT-002-06): an EXACT/EXACT
     * IST-AMS round trip is one two-slice provider request; each listed offer carries both directions in order with
     * UTC-based durations across the 2026-10-25 EU DST change; offers with a missing, reversed or foreign slice are dropped.
     */
    #[Test]
    public function an_ist_ams_round_trip_is_one_two_slice_search_listing_ordered_outbound_and_inbound_segments(): void
    {
        Http::fake(['api.duffel.com/air/offer_requests*' => Http::response($this->body([
            $this->roundTripOffer('off_rt'),
            $this->roundTripOffer('off_one_slice', slices: 1),
            $this->roundTripOffer('off_reversed', reversed: true),
            $this->roundTripOffer('off_elsewhere', inboundDestination: 'SAW'),
        ]))]);
        $tripId = $this->createRoundTrip(['type' => 'exact', 'outbound' => '2026-10-24', 'return' => '2026-10-31']);

        $this->get('/planlar/'.$tripId.'/ucuslar')->assertOk()
            ->assertSee('data-search-start', false)
            ->assertDontSee('data-journey-blocker', false);

        $id = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => $tripId], ['Idempotency-Key' => 'listing-5'])->assertAccepted()->json('search_id');
        $poll = $this->getJson('/api/v1/flight-search-jobs/'.$id)->assertOk();

        self::assertSame('COMPLETED', $poll->json('status'));
        self::assertSame(['off_rt'], array_column($poll->json('result.offers'), 'provider_offer_id'));
        $offer = $poll->json('result.offers.0');
        self::assertSame('ROUND_TRIP', $offer['journey_type']);
        self::assertSame(['OUTBOUND', 'INBOUND'], array_column($offer['segments'], 'direction'));
        self::assertSame(['TK1951', 'TK1952'], array_map(fn (array $s): string => $s['operating_carrier'].$s['operating_flight_number'], $offer['segments']));
        // Local clock and zone kept; duration from UTC instants (CEST outbound, CET inbound).
        self::assertSame(['2026-10-24T10:05:00', 'Europe/Amsterdam', '2026-10-24T08:05:00+00:00'], array_values($offer['segments'][0]['arrival']));
        self::assertSame([215, 205], array_column($offer['segments'], 'duration_minutes'));
        // One total for the whole journey; nothing per direction.
        self::assertSame(['minor_units' => 41000, 'currency' => 'EUR'], $offer['price']['value']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->data()['data']['slices'] === [
            ['origin' => 'IST', 'destination' => 'AMS', 'departure_date' => '2026-10-24'],
            ['origin' => 'AMS', 'destination' => 'IST', 'departure_date' => '2026-10-31'],
        ]);
        $this->getJson('/api/v1/offers/off_rt')->assertOk()->assertJsonPath('journey_type', 'ROUND_TRIP')->assertJsonPath('segments.1.direction', 'INBOUND');
    }

    #[Test]
    public function a_flexible_round_trip_shows_the_truthful_blocker_and_never_reaches_the_provider(): void
    {
        Http::fake();
        $tripId = $this->createRoundTrip(['type' => 'flexible', 'outbound' => '2026-10-24', 'return' => '2026-10-31']);

        // OD-022 FLEXIBLE stays OPEN: the page explains the limitation in Turkish and offers no search start.
        $this->get('/planlar/'.$tripId.'/ucuslar')->assertOk()
            ->assertSee('data-journey-blocker', false)
            ->assertSee('data-flight-search-blocked', false)
            ->assertSee('Esnek tarih aralığıyla uçuş araması henüz yapılamıyor')
            ->assertSee('Talep iki ayrı tek yön aramaya bölünmez.')
            ->assertDontSee('data-search-start', false)
            ->assertDontSee('Gidiş-dönüş uçuş araması henüz yapılamıyor');

        // A direct API start is not split into one-way searches and never sends provider HTTP.
        $id = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => $tripId], ['Idempotency-Key' => 'listing-6'])->assertAccepted()->json('search_id');
        $poll = $this->getJson('/api/v1/flight-search-jobs/'.$id)->assertOk();
        self::assertSame('FAILED', $poll->json('status'));
        self::assertNull($poll->json('result'));
        Http::assertNothingSent();
    }

    /** @param array{type: string, outbound: string, return: string} $dates */
    private function createRoundTrip(array $dates): string
    {
        $selection = $this->app->make(AviationLocationSelection::class);
        $date = fn (string $prefix, string $value): array => $dates['type'] === 'exact'
            ? [$prefix.'_date_mode' => 'exact', $prefix.'_exact_date' => $value]
            : [$prefix.'_date_mode' => 'flexible', $prefix.'_start_date' => $value, $prefix.'_end_date' => (new DateTimeImmutable($value))->modify('+2 days')->format('Y-m-d')];
        $response = $this->post('/planla', [
            'origin_selection' => $selection->issue(new LocationOption('duffel:arp_ist', 'airport', 'Istanbul Airport', 'TR', 'Europe/Istanbul', null, null, AviationIdentity::of('IATA_AIRPORT', 'IST'))),
            'destination_selection' => $selection->issue(new LocationOption('duffel:arp_ams', 'airport', 'Amsterdam Schiphol Airport', 'NL', 'Europe/Amsterdam', null, null, AviationIdentity::of('IATA_AIRPORT', 'AMS'))),
            'journey_type' => 'ROUND_TRIP',
            'traveler_count' => '1', 'adults' => '1', 'children' => '0', 'infants' => '0',
        ] + $date('outbound', $dates['outbound']) + $date('return', $dates['return']))->assertRedirect();

        return basename((string) $response->headers->get('Location'));
    }

    /** A Duffel two-slice offer IST->AMS (2026-10-24, CEST) and AMS->IST (2026-10-31, CET). */
    private function roundTripOffer(string $id, int $slices = 2, bool $reversed = false, string $inboundDestination = 'IST'): array
    {
        $segment = fn (string $number, string $from, string $fromTz, string $dep, string $to, string $toTz, string $arr): array => [
            'id' => 'seg_'.$id.$number,
            'marketing_carrier' => ['iata_code' => 'TK'], 'marketing_carrier_flight_number' => $number,
            'operating_carrier' => ['iata_code' => 'TK'], 'operating_carrier_flight_number' => $number,
            'departing_at' => $dep, 'arriving_at' => $arr,
            'origin' => ['iata_code' => $from, 'iata_city_code' => $from, 'time_zone' => $fromTz],
            'destination' => ['iata_code' => $to, 'iata_city_code' => $to, 'time_zone' => $toTz],
            'passengers' => [['passenger_id' => 'pas_1', 'baggages' => [['type' => 'checked', 'quantity' => 1]]]],
        ];
        $outbound = ['segments' => [$segment('1951', 'IST', 'Europe/Istanbul', '2026-10-24T07:30:00', 'AMS', 'Europe/Amsterdam', '2026-10-24T10:05:00')]];
        $inbound = ['segments' => [$segment('1952', 'AMS', 'Europe/Amsterdam', '2026-10-31T11:20:00', $inboundDestination, 'Europe/Istanbul', '2026-10-31T16:45:00')]];
        $all = $reversed ? [$inbound, $outbound] : [$outbound, $inbound];

        return [
            'id' => $id, 'total_amount' => '410.00', 'total_currency' => 'EUR', 'expires_at' => '2026-12-01T10:00:00Z',
            'owner' => ['iata_code' => 'TK', 'name' => 'Turkish Airlines'],
            'slices' => array_slice($all, 0, $slices),
        ];
    }

    private function useCapability(bool $searchSupported): void
    {
        $capabilities = new InMemoryProviderCapabilityRepository;
        if ($searchSupported) {
            $capabilities->save(new ProviderCapability('duffel_flights', 'search', 'production', true, 'FRESH', 'test evidence', new DateTimeImmutable('2026-09-29T10:00:00+00:00')));
        }
        $this->app->instance(ProviderCapabilityMatrix::class, new ProviderCapabilityMatrixService($capabilities));
    }

    /** Creates the TripRequest through the real POST /planla and returns its id. */
    private function createTrip(int $adults = 1, int $children = 0): string
    {
        $selection = $this->app->make(AviationLocationSelection::class);
        $origin = new LocationOption('duffel:arp_ist', 'airport', 'Istanbul Airport', 'TR', 'Europe/Istanbul', null, null, AviationIdentity::of('IATA_AIRPORT', 'IST'));
        $destination = new LocationOption('duffel:arp_lhr', 'airport', 'Heathrow Airport', 'GB', 'Europe/London', null, null, AviationIdentity::of('IATA_AIRPORT', 'LHR'));

        $response = $this->post('/planla', [
            'origin_selection' => $selection->issue($origin),
            'destination_selection' => $selection->issue($destination),
            'journey_type' => 'ONE_WAY',
            'outbound_date_mode' => 'exact',
            'outbound_exact_date' => '2026-12-15',
            'traveler_count' => (string) ($adults + $children),
            'adults' => (string) $adults,
            'children' => (string) $children,
            'infants' => '0',
        ]);
        $response->assertRedirect();

        return basename((string) $response->headers->get('Location'));
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
     * @param  list<array<string, mixed>>  $baggages
     * @return array<string, mixed>
     */
    private function offer(string $id, string $amount, string $currency, string $number, array $baggages): array
    {
        return [
            'id' => $id, 'total_amount' => $amount, 'total_currency' => $currency, 'expires_at' => '2026-12-01T10:00:00Z',
            'owner' => ['iata_code' => 'TK', 'name' => 'Turkish Airlines'],
            'slices' => [['segments' => [[
                'id' => 'seg_'.$id,
                'marketing_carrier' => ['iata_code' => 'TK'], 'marketing_carrier_flight_number' => $number,
                'operating_carrier' => ['iata_code' => 'TK'], 'operating_carrier_flight_number' => $number,
                'departing_at' => '2026-12-15T08:30:00', 'arriving_at' => '2026-12-15T10:45:00',
                'origin' => ['iata_code' => 'IST', 'time_zone' => 'Europe/Istanbul'],
                'destination' => ['iata_code' => 'LHR', 'time_zone' => 'Europe/London'],
                'passengers' => [['passenger_id' => 'pas_1', 'baggages' => $baggages]],
            ]]]],
        ];
    }
}
