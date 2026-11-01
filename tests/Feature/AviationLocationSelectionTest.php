<?php

namespace Tests\Feature;

use App\Http\Support\AviationLocationSelection;
use App\Modules\Api\Application\TripRequestApplicationService;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Api\Domain\TripRequest\LocationRef;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Domain\TripRequest\TripRequestRepository;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\FlightSearch\Application\FlightOfferAggregator;
use App\Modules\FlightSearch\Application\LocationGatedFlightSearchExecutor;
use App\Modules\FlightSearch\Application\ResilientFlightSearchExecutor;
use App\Modules\FlightSearch\Application\UnsupportedFlightSearchLocation;
use App\Modules\FlightSearch\Contracts\FlightProviderAdapter;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\ProviderAcl\Application\CircuitBreaker;
use App\Modules\ProviderAcl\Application\ResilientProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\AviationIdentity;
use App\Modules\ProviderAcl\Contracts\LocationOption;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AviationLocationSelectionTest extends TestCase
{
    private const TOKEN = 'duffel_test_secret_token';

    private InMemoryTripRequestRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        // Test-only values (short timeouts, no real token); the deploy-time profile is covered by DuffelPlacesResilienceTest.
        config([
            'services.duffel.access_token' => self::TOKEN,
            'provider_resilience.providers.duffel_places' => [
                'version' => 'test-1',
                'timeout_ms' => 1000,
                'retry' => ['max_attempts' => 2, 'base_delay_ms' => 10, 'max_delay_ms' => 20],
                'circuit_breaker' => ['failure_threshold' => 5, 'open_seconds' => 30],
                'rate_limit' => ['max_requests' => 100, 'per_seconds' => 60],
            ],
        ]);
        $this->app->bind(ProviderCallGuard::class, fn ($app): ProviderCallGuard => new ResilientProviderCallGuard(
            $app->make(ResiliencePolicies::class),
            $app->make(CircuitBreaker::class),
            $app->make(RateLimiter::class),
            fn (int $ceilingMs): int => 0,
        ));
        $this->repository = new InMemoryTripRequestRepository;
        $this->app->instance(TripRequestService::class, new TripRequestApplicationService($this->repository));
    }

    #[Test]
    public function airport_and_city_results_are_normalized_to_distinct_iata_identities_without_provider_ids(): void
    {
        Http::fake(['api.duffel.com/places/suggestions*' => Http::response(['data' => [
            $this->airport('arp_ist_tr', 'IST', 'Istanbul Airport', 'Istanbul', 'Europe/Istanbul'),
            $this->airport('arp_saw_tr', 'SAW', 'Sabiha Gökçen International Airport', 'Istanbul', 'Europe/Istanbul'),
            $this->city('cit_ist_tr', 'IST', 'Istanbul', null, ['Europe/Istanbul', 'Europe/Istanbul']),
        ]])]);

        $response = $this->getJson('/konumlar/ara?baglam=ucus&q=istanbul')->assertOk();
        $locations = $response->json('locations');

        self::assertSame([
            ['kind' => 'airport', 'label' => 'Istanbul Airport', 'country_code' => 'TR', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'IST']],
            ['kind' => 'airport', 'label' => 'Sabiha Gökçen International Airport, Istanbul', 'country_code' => 'TR', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'SAW']],
            ['kind' => 'city', 'label' => 'Istanbul', 'country_code' => 'TR', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_CITY', 'code' => 'IST']],
        ], array_map(fn (array $entry): array => array_diff_key($entry, ['selection' => true]), $locations));

        // Duffel resource ids are neither the aviation identity nor sent to the browser.
        foreach (['arp_ist_tr', 'arp_saw_tr', 'cit_ist_tr', 'duffel:', 'Duffel', self::TOKEN] as $hidden) {
            self::assertStringNotContainsString($hidden, $response->getContent());
        }
        $selection = $this->app->make(AviationLocationSelection::class);
        self::assertSame('duffel:arp_ist_tr', $selection->open($locations[0]['selection'])?->id);
        self::assertSame('duffel:cit_ist_tr', $selection->open($locations[2]['selection'])?->id);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.duffel.com/places/suggestions?')
            && $request['query'] === 'istanbul'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->hasHeader('Duffel-Version', 'v2'));
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_selected_country_narrows_flight_results_by_their_canonical_country_code(): void
    {
        Http::fake(['api.duffel.com/places/suggestions*' => Http::response(['data' => [
            array_merge($this->airport('arp_rom_it', 'FCO', 'Leonardo da Vinci', 'Rome', 'Europe/Rome'), ['iata_country_code' => 'IT']),
            array_merge($this->airport('arp_rom_us', 'RME', 'Griffiss', 'Rome', 'America/New_York'), ['iata_country_code' => 'US']),
        ]])]);

        $italy = $this->getJson('/konumlar/ara?baglam=ucus&ulke=it&q=roma')->assertOk()->json('locations');
        self::assertSame(['FCO'], array_column(array_column($italy, 'aviation_identity'), 'code'));
        self::assertCount(2, $this->getJson('/konumlar/ara?baglam=ucus&q=roma')->assertOk()->json('locations'));
        $this->getJson('/konumlar/ara?baglam=ucus&ulke=TR&q=roma')->assertOk()->assertExactJson(['locations' => []]);
    }

    #[Test]
    public function malformed_or_unverifiable_places_are_dropped_and_malformed_payload_is_a_safe_error(): void
    {
        Http::fake(['api.duffel.com/*' => Http::sequence()
            ->push(['data' => [
                $this->airport('arp_bad_tr', 'ist', 'Lowercase', 'Istanbul', 'Europe/Istanbul'),
                $this->airport('arp_tz1_tr', 'ESB', 'Esenboğa', 'Ankara', 'Mars/Olympus'),
                $this->airport('arp_tz2_tr', 'ADB', 'Adnan Menderes', 'Izmir', null),
                $this->city('cit_mix_xx', 'MIX', 'Mixed', null, ['Europe/Istanbul', 'Europe/Rome']),
                $this->city('cit_non_xx', 'NON', 'No airports', null, []),
                ['type' => 'station', 'id' => 'stn_x', 'iata_code' => 'XYZ', 'iata_country_code' => 'TR', 'name' => 'Station', 'time_zone' => 'Europe/Istanbul'],
                ['type' => 'airport', 'iata_code' => 'AYT', 'iata_country_code' => 'TR', 'name' => 'No id', 'time_zone' => 'Europe/Istanbul'],
                array_merge($this->airport('arp_cc_tr', 'DLM', 'Dalaman', 'Dalaman', 'Europe/Istanbul'), ['iata_country_code' => 'tr']),
                'broken',
            ]])
            ->push(['unexpected' => []])]);

        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertOk()->assertExactJson(['locations' => []]);
        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')
            ->assertStatus(503)
            ->assertJsonPath('error.message_tr', 'Sağlayıcıya şu anda ulaşılamıyor. Havalimanı ve şehir araması şu anda yapılamıyor.');
    }

    #[Test]
    public function timeout_rate_limit_and_provider_failure_become_safe_turkish_errors_without_the_token(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->message, $event->context];
        });
        Http::fake(['api.duffel.com/*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out')
            ->pushFailedConnection('cURL error 28: Operation timed out')
            ->push(['errors' => [['message' => 'rate limited '.self::TOKEN]]], 429)
            ->push('<html>upstream '.self::TOKEN.'</html>', 500)
            ->push('<html>upstream '.self::TOKEN.'</html>', 500)]);

        $timeout = $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)
            ->assertJsonPath('error.code', 'PROVIDER_TIMEOUT')
            ->assertJsonPath('error.message_tr', 'Sağlayıcı yanıtı zamanında alınamadı. Havalimanı ve şehir araması şu anda yapılamıyor.');
        $limited = $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)
            ->assertJsonPath('error.code', 'PROVIDER_RATE_LIMITED');
        $failure = $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)->assertJsonPath('error.code', null);

        // Timeout and 5xx are retried once by the guard; 429 is not.
        Http::assertSentCount(5);
        foreach ([$timeout->getContent(), $limited->getContent(), $failure->getContent(), json_encode($logged, JSON_THROW_ON_ERROR)] as $body) {
            self::assertStringNotContainsString(self::TOKEN, $body);
            self::assertStringNotContainsString('cURL', $body);
            self::assertStringNotContainsString('upstream', $body);
        }
    }

    #[Test]
    public function invalid_flight_queries_are_rejected_without_calling_duffel(): void
    {
        Http::fake();

        foreach (['/konumlar/ara?baglam=ucus&q=is%07t', '/konumlar/ara?baglam=ucus&q='.str_repeat('a', 81), '/konumlar/ara?baglam=tren&q=ist', '/konumlar/ara?baglam=ucus&ulke=TUR&q=ist', '/konumlar/ara?baglam=ucus&q[]=ist'] as $url) {
            $this->getJson($url)->assertStatus(422)->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED');
        }
        $this->getJson('/konumlar/ara?baglam=ucus&q=i')->assertOk()->assertExactJson(['locations' => []]);

        Http::assertNothingSent();
    }

    #[Test]
    public function aviation_search_stays_disabled_without_token_or_approved_resilience_profile(): void
    {
        Http::fake();

        config(['provider_resilience.providers.duffel_places' => null]);
        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503)
            ->assertJsonPath('error.message_tr', 'Havalimanı ve şehir araması henüz kullanıma açılmadı.');
        $this->get('/')->assertOk()->assertDontSee('baglam=ucus', false)->assertDontSee('name="origin_selection" value="x"', false);

        config(['services.duffel.access_token' => '']);
        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503);

        Http::assertNothingSent();
        // An incomplete profile (any missing or non-positive value) fails closed as well.
        config(['services.duffel.access_token' => 'token', 'provider_resilience.providers.duffel_places.rate_limit.max_requests' => 0]);
        $this->getJson('/konumlar/ara?baglam=ucus&q=ist')->assertStatus(503);
        Http::assertNothingSent();
    }

    #[Test]
    public function planner_uses_the_same_origin_flight_endpoint_and_never_exposes_the_token_or_duffel(): void
    {
        $content = $this->get('/')->assertOk()->getContent();
        $script = (string) file_get_contents(public_path('assets/trip-planner.js'));

        self::assertStringContainsString('data-flight-location-search-url="'.route('locations.search', ['baglam' => 'ucus']).'"', $content);
        self::assertStringEndsWith('/konumlar/ara?baglam=ucus', route('locations.search', ['baglam' => 'ucus']));
        self::assertStringContainsString('name="origin_selection"', $content);
        self::assertStringContainsString('Kalkış ülkesini seçin, ardından havalimanını veya şehri arayıp listeden seçin.', $content);
        foreach ([self::TOKEN, 'api.duffel.com', 'duffel'] as $hidden) {
            self::assertStringNotContainsStringIgnoringCase($hidden, $content);
            self::assertStringNotContainsStringIgnoringCase($hidden, $script);
        }
    }

    #[Test]
    public function selected_airport_and_city_identities_survive_to_the_flight_provider_adapter_boundary(): void
    {
        $origin = $this->selectionFor(new LocationOption('duffel:arp_ist_tr', 'airport', 'Istanbul Airport', 'TR', 'Europe/Istanbul', null, null, AviationIdentity::of('IATA_AIRPORT', 'IST')));
        $destination = $this->selectionFor(new LocationOption('duffel:cit_rom_it', 'city', 'Rome', 'IT', 'Europe/Rome', null, null, AviationIdentity::of('IATA_CITY', 'ROM')));

        // Browser-sent location fields are replaced by the server-issued selection.
        $this->post('/planla', $this->form([
            'origin_kind' => 'station', 'origin_value' => 'forged', 'origin_timezone' => 'Asia/Tokyo', 'origin_selection' => $origin,
            'destination_selection' => $destination,
        ]))->assertRedirect();

        $stored = $this->repository->only();
        self::assertSame(['kind' => 'airport', 'value' => 'duffel:arp_ist_tr', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'IST']], $stored->originPlace?->toContract());
        self::assertSame(['kind' => 'city', 'value' => 'duffel:cit_rom_it', 'iana_timezone' => 'Europe/Rome', 'aviation_identity' => ['type' => 'IATA_CITY', 'code' => 'ROM']], $stored->destinationPlace?->toContract());

        $view = $this->app->make(TripRequestService::class)->get((string) $stored->id);
        self::assertNotNull($view);
        // Public /api/v1 representation (SCH-TripRequest v3.2) stays unchanged.
        self::assertArrayNotHasKey('aviation_identity', $view->toContract()['origin_place']);

        $criteria = FlightSearchCriteria::fromSearchableTripRequest((string) $stored->id, $view);
        self::assertNull($criteria->locationBlockerTr());
        $adapter = new RecordingCriteriaAdapter;
        $this->gatedExecutor($adapter)->execute($criteria, fn (): null => null);

        self::assertCount(1, $adapter->received);
        self::assertSame(['type' => 'IATA_AIRPORT', 'code' => 'IST'], $adapter->received[0]->originPlace['aviation_identity']);
        self::assertSame(['type' => 'IATA_CITY', 'code' => 'ROM'], $adapter->received[0]->destinationPlace['aviation_identity']);

        // The visible title keeps useful aviation codes; ordinary users never receive catalog/provider identities.
        $summary = $this->get('/planlar/'.$stored->id)->assertOk()->getContent();
        self::assertMatchesRegularExpression('/<h1 class="page-title">IST <span aria-hidden="true">→<\/span><span class="visually-hidden">ile<\/span> ROM<\/h1>/', $summary);
        self::assertStringNotContainsString('duffel:arp_ist_tr', $summary);
        self::assertStringNotContainsString('duffel:cit_rom_it', $summary);
        self::assertStringNotContainsString('Teknik ayrıntılar', $summary);
    }

    #[Test]
    public function browser_cannot_fabricate_an_iata_identity(): void
    {
        $valid = $this->selectionFor(new LocationOption('duffel:arp_ist_tr', 'airport', 'Istanbul Airport', 'TR', 'Europe/Istanbul', null, null, AviationIdentity::of('IATA_AIRPORT', 'IST')));
        $forgedPayload = ['id' => 'x', 'label' => 'x', 'country_code' => 'TR', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'XXX']];
        $forged = [
            'plain-IST',
            base64_encode((string) json_encode(['purpose' => 'aviation-location-selection:v1', 'kind' => 'airport'] + $forgedPayload)),
            substr($valid, 0, -4).'AAAA',
            encrypt($forgedPayload),
            app('encrypter')->encryptString((string) json_encode(['purpose' => 'other', 'kind' => 'airport'] + $forgedPayload)),
            app('encrypter')->encryptString((string) json_encode(['purpose' => 'aviation-location-selection:v1', 'kind' => 'city'] + $forgedPayload)),
        ];

        foreach ($forged as $token) {
            $this->post('/planla', $this->form(['origin_selection' => $token, 'destination_selection' => $valid]))
                ->assertSessionHasErrors(['origin_selection' => 'Seçilen başlangıç konumu doğrulanamadı; listeden yeniden seçin.']);
        }
        self::assertSame(0, $this->repository->count());

        // Browser fields named like the contract are ignored; without a token no aviation identity is stored.
        $this->post('/planla', $this->form([
            'origin_kind' => 'airport', 'origin_value' => 'duffel:arp_xxx', 'origin_timezone' => 'Europe/Istanbul',
            'origin_aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'XXX'],
            'destination_selection' => $valid,
        ]))->assertRedirect();
        self::assertArrayNotHasKey('aviation_identity', $this->repository->only()->originPlace?->toContract() ?? []);

        // The public API never accepts an aviation identity from a client.
        $this->withToken(\Tests\Fakes\FixedBearerAuthenticator::TOKEN)->postJson('/api/v1/trip-requests', [
            'status' => 'DRAFT',
            'origin_place' => ['kind' => 'airport', 'value' => 'x', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'XXX']],
        ])->assertStatus(400)->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED');
    }

    #[Test]
    public function selection_token_restores_only_server_normalized_display_fields(): void
    {
        Http::fake();
        $token = $this->selectionFor(new LocationOption('duffel:arp_saw_tr', 'airport', 'Sabiha Gökçen', 'TR', 'Europe/Istanbul', null, null, AviationIdentity::of('IATA_AIRPORT', 'SAW')));

        $this->getJson('/konumlar/cozumle?secim='.urlencode($token))
            ->assertOk()
            ->assertJsonPath('location.label', 'Sabiha Gökçen')
            ->assertJsonPath('location.aviation_identity', ['type' => 'IATA_AIRPORT', 'code' => 'SAW'])
            ->assertJsonMissingPath('location.id');
        $this->getJson('/konumlar/cozumle?secim=forged')->assertNotFound()
            ->assertJsonPath('error.message_tr', 'Seçilen konum doğrulanamadı; listeden yeniden seçin.');
        Http::assertNothingSent();
    }

    #[Test]
    public function location_gate_blocks_missing_invalid_mismatched_and_station_identities_before_the_adapter(): void
    {
        $airport = ['kind' => 'airport', 'value' => 'duffel:arp_ist_tr', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'IST']];
        $cases = [
            'missing' => ['kind' => 'airport', 'value' => 'geonames:6299784', 'iana_timezone' => 'Europe/Istanbul'],
            'invalid' => ['kind' => 'airport', 'value' => 'x', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'ist']],
            'mismatch' => ['kind' => 'airport', 'value' => 'x', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_CITY', 'code' => 'IST']],
            'other kind' => ['kind' => 'supported_location', 'value' => 'x', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_CITY', 'code' => 'IST']],
            'station' => ['kind' => 'station', 'value' => 'x', 'iana_timezone' => 'Europe/Istanbul'],
        ];

        foreach ($cases as $name => $place) {
            $adapter = new RecordingCriteriaAdapter;
            $criteria = FlightSearchCriteria::fromSearchableTripRequest('trip-1', new TripRequestView(
                'SEARCHABLE', $airport, $place, null, 1, null, 'ONE_WAY', ['type' => 'EXACT', 'date' => '2026-10-15'],
            ));
            try {
                $this->gatedExecutor($adapter)->execute($criteria, fn (): null => null);
                self::fail("{$name} must be blocked.");
            } catch (UnsupportedFlightSearchLocation $blocked) {
                self::assertSame($name === 'station'
                    ? 'İstasyon konumları uçuş aramasında kullanılamaz.'
                    : 'Uçuş araması için başlangıç ve varış konumlarının havalimanı veya şehir aramasından seçilmiş olması gerekir; bu seyahat talebindeki konumların havacılık kimliği (IATA kodu) yok.', $blocked->getMessage());
            }
            self::assertSame([], $adapter->received, $name);
        }

        // The domain rejects a stored identity that is invalid or does not match the kind.
        self::assertNull(LocationRef::fromNullable($cases['invalid']));
        self::assertNull(LocationRef::fromNullable($cases['mismatch']));
        self::assertNotNull(LocationRef::fromNullable($cases['missing']));
    }

    /** @return array<string, mixed> */
    private function airport(string $id, string $code, string $name, string $city, ?string $timezone): array
    {
        return ['type' => 'airport', 'id' => $id, 'iata_code' => $code, 'iata_city_code' => $code, 'iata_country_code' => 'TR', 'icao_code' => null,
            'name' => $name, 'city_name' => $city, 'time_zone' => $timezone, 'latitude' => 41.26, 'longitude' => 28.74, 'airports' => null];
    }

    /**
     * @param  list<string>  $airportZones
     * @return array<string, mixed>
     */
    private function city(string $id, string $code, string $name, ?string $timezone, array $airportZones): array
    {
        return ['type' => 'city', 'id' => $id, 'iata_code' => $code, 'iata_country_code' => 'TR', 'name' => $name, 'time_zone' => $timezone,
            'airports' => array_map(fn (string $zone): array => ['type' => 'airport', 'id' => 'arp_x', 'iata_code' => 'XXX', 'time_zone' => $zone], $airportZones)];
    }

    private function selectionFor(LocationOption $location): string
    {
        return $this->app->make(AviationLocationSelection::class)->issue($location);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides): array
    {
        return $overrides + [
            'origin_kind' => '', 'origin_value' => '', 'origin_timezone' => '',
            'destination_kind' => '', 'destination_value' => '', 'destination_timezone' => '',
            'journey_type' => 'ONE_WAY', 'outbound_date_mode' => 'exact', 'outbound_exact_date' => '2026-10-15', 'traveler_count' => 1,
        ];
    }

    private function gatedExecutor(RecordingCriteriaAdapter $adapter): LocationGatedFlightSearchExecutor
    {
        return new LocationGatedFlightSearchExecutor(new ResilientFlightSearchExecutor([$adapter], new PassThroughCallGuard, new FlightOfferAggregator));
    }
}

/** Stores rows as JSON like DatabaseTripRequestRepository, so place encoding round-trips. */
final class InMemoryTripRequestRepository implements TripRequestRepository
{
    /** @var array<string, string> */
    private array $rows = [];

    public function save(TripRequest $tripRequest): TripRequest
    {
        $stored = $tripRequest->withId('trip-'.(count($this->rows) + 1));
        $this->rows[(string) $stored->id] = (string) json_encode([
            'status' => $stored->status,
            'origin_place' => $stored->originPlace?->toContract(),
            'destination_place' => $stored->destinationPlace?->toContract(),
            'date_window' => $stored->dateWindow,
            'journey_type' => $stored->journeyType,
            'outbound_date' => $stored->outboundDate,
            'return_date' => $stored->returnDate,
            'traveler_count' => $stored->travelerCount,
            'preferences' => $stored->preferences,
            'owner_issuer' => $stored->ownerIssuer,
            'owner_subject' => $stored->ownerSubject,
        ], JSON_THROW_ON_ERROR);

        return $stored;
    }

    public function find(string $id): ?TripRequest
    {
        if (! isset($this->rows[$id])) {
            return null;
        }
        $row = json_decode($this->rows[$id], true, 512, JSON_THROW_ON_ERROR);

        return new TripRequest($id, $row['status'], LocationRef::fromNullable($row['origin_place']), LocationRef::fromNullable($row['destination_place']), $row['date_window'], $row['traveler_count'], $row['preferences'], $row['journey_type'], $row['outbound_date'], $row['return_date'], ownerIssuer: $row['owner_issuer'], ownerSubject: $row['owner_subject']);
    }

    /** @return list<TripRequest> */
    public function ownedBy(PrincipalRef $owner): array
    {
        return array_values(array_filter(
            array_map(fn (string $id): ?TripRequest => $this->find($id), array_keys($this->rows)),
            fn (?TripRequest $tripRequest): bool => $tripRequest?->owner()?->equals($owner) ?? false,
        ));
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function only(): TripRequest
    {
        return $this->find((string) array_key_last($this->rows)) ?? throw new \LogicException('No trip request stored.');
    }
}

final class RecordingCriteriaAdapter implements FlightProviderAdapter
{
    /** @var list<FlightSearchCriteria> */
    public array $received = [];

    public function providerId(): string
    {
        return 'recording';
    }

    public function search(FlightSearchCriteria $criteria, ?int $timeoutMs = null): array
    {
        $this->received[] = $criteria;

        return [];
    }
}

final class PassThroughCallGuard implements ProviderCallGuard
{
    public function call(string $providerId, callable $operation): mixed
    {
        return $operation(1000);
    }
}
