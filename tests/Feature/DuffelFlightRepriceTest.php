<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use App\Modules\FlightSearch\Domain\RepriceReason;
use App\Modules\FlightSearch\Infrastructure\Duffel\DuffelFlightProviderAdapter;
use App\Modules\ProviderAcl\Application\CircuitBreaker;
use App\Modules\ProviderAcl\Application\ProviderCapabilityMatrixService;
use App\Modules\ProviderAcl\Application\ResilientProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use App\Modules\ProviderAcl\Domain\ProviderCapability;
use Closure;
use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryFlightOfferRepository;
use Tests\Fakes\InMemoryProviderCapabilityRepository;
use Tests\TestCase;

/**
 * FLT-REPRICE-001 / OP-repriceOffer against the Duffel boundary (HTTP faked): a searched offer is re-fetched by its
 * provider reference (GET /air/offers/{id}) and only the canonical RepriceResult leaves the application.
 */
final class DuffelFlightRepriceTest extends TestCase
{
    private const TOKEN = 'duffel_reprice_secret_token';

    private const OFFER_ID = 'off_0000AzReprice01';

    private InMemoryProviderCapabilityRepository $capabilities;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'services.duffel.access_token' => self::TOKEN,
            'services.duffel.flights.passenger_policy' => 'ALL_ADULT',
            'services.duffel.flights.max_offers' => 20,
            'provider_resilience.providers.duffel_flights' => [
                'version' => 'test-1',
                'timeout_ms' => 30000,
                'retry' => ['max_attempts' => 1, 'base_delay_ms' => 10, 'max_delay_ms' => 20],
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
        $this->capabilities = new InMemoryProviderCapabilityRepository;
        foreach (['search', 'reprice'] as $operation) {
            $this->capabilities->save(new ProviderCapability('duffel_flights', $operation, 'production', true, 'FRESH', 'test evidence', new DateTimeImmutable('2026-09-29T10:00:00+00:00')));
        }
        $this->app->instance(ProviderCapabilityMatrix::class, new ProviderCapabilityMatrixService($this->capabilities));
        $this->app->instance(FlightOfferRepository::class, new InMemoryFlightOfferRepository);
    }

    #[Test]
    public function ts_01_06_an_unchanged_offer_is_re_fetched_by_its_provider_reference_and_stays_current(): void
    {
        $contract = $this->searchThenReprice(fn () => Http::response(['data' => $this->offer()]))->assertOk()->json();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.duffel.com/air/offers/'.self::OFFER_ID.'?return_available_services=false'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->hasHeader('Duffel-Version'));
        self::assertSame('CURRENT', $contract['current']['offer']['state']);
        self::assertSame([], $contract['reason_codes']);
        self::assertTrue($contract['previous_approval_valid']);
        self::assertTrue($contract['continuation_allowed']);
        self::assertFalse($contract['changes']['critical_change']);
        self::assertSame(34560, $contract['current']['offer']['price']['value']['minor_units']);
        // Provenance: the Duffel source with its own offer id and a fresh verification time.
        $source = $contract['current']['offer']['sources'][0];
        self::assertSame('duffel_flights', $source['provider_id']);
        self::assertSame(self::OFFER_ID, $source['provider_offer_id']);
        self::assertNotNull($source['verified_at']);
        self::assertTrue($contract['reprice_capability']['supported']);
        // Model A: nothing is marked purchasable.
        self::assertFalse($contract['current']['offer']['bookable']);
        $this->assertNoProviderPayload($contract);
    }

    #[Test]
    public function ts_03_a_changed_provider_price_invalidates_the_previous_approval(): void
    {
        $contract = $this->searchThenReprice(fn () => Http::response(['data' => $this->offer(amount: '399.90')]))->assertOk()->json();

        self::assertSame('CHANGED', $contract['current']['offer']['state']);
        self::assertSame([RepriceReason::OFFER_CHANGED], $contract['reason_codes']);
        self::assertTrue($contract['changes']['price_changed']);
        self::assertFalse($contract['changes']['baggage_changed']);
        self::assertFalse($contract['previous_approval_valid']);
        self::assertFalse($contract['continuation_allowed']);
        self::assertSame(34560, $contract['previous']['offer']['price']['value']['minor_units']);
        self::assertSame(39990, $contract['current']['offer']['price']['value']['minor_units']);
        $this->assertNoProviderPayload($contract);
    }

    #[Test]
    public function ts_07_a_baggage_change_is_critical_and_a_missing_bag_stays_unknown(): void
    {
        $contract = $this->searchThenReprice(fn () => Http::response(['data' => $this->offer(baggages: [])]))->assertOk()->json();

        self::assertSame('CHANGED', $contract['current']['offer']['state']);
        self::assertTrue($contract['changes']['baggage_changed']);
        self::assertFalse($contract['changes']['price_changed']);
        self::assertFalse($contract['previous_approval_valid']);
        $checked = $contract['current']['offer']['baggage']['checked_baggage'];
        self::assertNull($checked['value'], 'a bag the provider no longer lists is unknown, never "not included"');
        self::assertSame('UNKNOWN', $checked['certainty']);
        self::assertTrue($contract['previous']['offer']['baggage']['checked_baggage']['value']);
    }

    #[Test]
    public function ts_04_an_offer_the_provider_reports_as_expired_is_not_current_or_purchasable(): void
    {
        $contract = $this->searchThenReprice(fn () => Http::response(['data' => $this->offer(expiresAt: '2026-01-01T00:00:00Z')]))->assertOk()->json();

        self::assertSame('EXPIRED', $contract['current']['offer']['state']);
        self::assertContains(RepriceReason::OFFER_EXPIRED, $contract['reason_codes']);
        self::assertFalse($contract['current']['offer']['bookable']);
        self::assertFalse($contract['previous_approval_valid']);
        self::assertFalse($contract['continuation_allowed']);
    }

    #[Test]
    public function ts_05_a_provider_timeout_leaves_the_offer_unverified(): void
    {
        $contract = $this->searchThenReprice(fn () => throw new ConnectionException('cURL error 28: Operation timed out'))->assertOk()->json();

        self::assertSame('UNVERIFIED', $contract['current']['offer']['state']);
        self::assertSame([RepriceReason::PROVIDER_TIMEOUT, RepriceReason::REPRICE_UNVERIFIED], $contract['reason_codes']);
        self::assertFalse($contract['previous_approval_valid']);
        self::assertFalse($contract['continuation_allowed']);
        // The search-time price is kept as-is; nothing is synthesized.
        self::assertSame(34560, $contract['current']['offer']['price']['value']['minor_units']);
        self::assertStringNotContainsString('cURL', (string) json_encode($contract));
    }

    /** @return array<string, array{0: string}> */
    public static function unconfirmedResponses(): array
    {
        return [
            'not found' => ['not_found'],
            'no longer available' => ['no_longer_available'],
            'server error' => ['server_error'],
            'another offer id' => ['another_offer'],
            'malformed offer' => ['malformed'],
        ];
    }

    #[Test]
    #[DataProvider('unconfirmedResponses')]
    public function an_offer_the_provider_does_not_confirm_is_unverified_without_provider_text(string $case): void
    {
        $response = match ($case) {
            'not_found' => fn () => Http::response(['errors' => [['code' => 'not_found', 'type' => 'invalid_request_error', 'message' => 'Offer not found']]], 404),
            'no_longer_available' => fn () => Http::response(['errors' => [['code' => 'offer_no_longer_available', 'type' => 'invalid_state_error', 'message' => 'The offer is no longer available']]], 422),
            'server_error' => fn () => Http::response(['errors' => [['message' => 'boom']]], 500),
            'another_offer' => fn () => Http::response(['data' => $this->offer(id: 'off_0000SomethingElse')]),
            'malformed' => fn () => Http::response(['data' => ['id' => self::OFFER_ID, 'total_amount' => 'free']]),
        };

        $contract = $this->searchThenReprice($response)->assertOk()->json();

        self::assertSame('UNVERIFIED', $contract['current']['offer']['state']);
        self::assertSame([RepriceReason::REPRICE_UNVERIFIED], $contract['reason_codes']);
        self::assertFalse($contract['continuation_allowed']);
        $json = (string) json_encode($contract);
        foreach (['Offer not found', 'no longer available', 'boom', 'off_0000SomethingElse', 'free'] as $providerText) {
            self::assertStringNotContainsString($providerText, $json);
        }
    }

    #[Test]
    public function ts_02_without_production_reprice_capability_no_provider_call_is_made(): void
    {
        $this->capabilities->save(new ProviderCapability('duffel_flights', 'reprice', 'production', false, 'UNKNOWN', null, null));

        $contract = $this->searchThenReprice(fn () => Http::response(['data' => $this->offer(amount: '999.00')]))->assertOk()->json();

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
        self::assertSame('UNVERIFIED', $contract['current']['offer']['state']);
        self::assertSame([RepriceReason::REPRICE_UNVERIFIED], $contract['reason_codes']);
        self::assertFalse($contract['reprice_capability']['supported']);
        self::assertSame(34560, $contract['current']['offer']['price']['value']['minor_units']);
    }

    #[Test]
    public function the_offer_page_uses_op_reprice_offer_and_offers_no_purchase(): void
    {
        $this->get('/teklifler/'.self::OFFER_ID)
            ->assertOk()
            ->assertSee('data-reprice-url="'.url('/api/v1/offers/'.self::OFFER_ID.'/reprice').'"', false)
            ->assertSee('Güncel fiyat ve koşullar')
            ->assertSee('Yeniden kontrol et')
            ->assertDontSee('Satın al');
    }

    /** Runs the real search (POST /air/offer_requests) and then OP-repriceOffer for the searched offer. */
    private function searchThenReprice(Closure $offerResponse): TestResponse
    {
        Http::fake([
            '*/air/offer_requests*' => Http::response(['data' => ['id' => 'orq_0001', 'live_mode' => false, 'offers' => [$this->offer()]]]),
            '*/air/offers/*' => $offerResponse,
        ]);
        $offers = $this->app->make(DuffelFlightProviderAdapter::class)->search($this->criteria(), 30000);
        self::assertCount(1, $offers);
        $this->app->make(FlightOfferRepository::class)->save($offers[0]);

        return $this->postJson('/api/v1/offers/'.self::OFFER_ID.'/reprice');
    }

    /** @param array<string, mixed> $contract */
    private function assertNoProviderPayload(array $contract): void
    {
        $json = (string) json_encode($contract);
        foreach (['total_amount', 'total_currency', 'slices', 'owner', 'live_mode', 'marketing_carrier', 'passengers', self::TOKEN] as $raw) {
            self::assertStringNotContainsString($raw, $json);
        }
    }

    private function criteria(): FlightSearchCriteria
    {
        $place = fn (string $code, string $tz): array => ['kind' => 'airport', 'value' => 'duffel:x_'.strtolower($code), 'iana_timezone' => $tz, 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => $code]];

        return FlightSearchCriteria::fromSearchableTripRequest('trip-duffel', new TripRequestView(
            status: 'SEARCHABLE',
            originPlace: $place('IST', 'Europe/Istanbul'),
            destinationPlace: $place('LHR', 'Europe/London'),
            dateWindow: null,
            travelerCount: 1,
            preferences: null,
            journeyType: 'ONE_WAY',
            outboundDate: ['type' => 'EXACT', 'date' => '2026-12-15'],
        ));
    }

    /**
     * Duffel-shaped offer (test mode) used for both the search result and GET /air/offers/{id}.
     *
     * @param  list<array<string, mixed>>|null  $baggages
     * @return array<string, mixed>
     */
    private function offer(string $amount = '345.60', ?array $baggages = null, string $expiresAt = '2026-12-01T10:00:00Z', string $id = self::OFFER_ID): array
    {
        return [
            'id' => $id,
            'live_mode' => false,
            'total_amount' => $amount,
            'total_currency' => 'GBP',
            'expires_at' => $expiresAt,
            'owner' => ['iata_code' => 'TK', 'name' => 'Turkish Airlines'],
            'slices' => [[
                'segments' => [[
                    'id' => 'seg_1',
                    'marketing_carrier' => ['iata_code' => 'TK'],
                    'marketing_carrier_flight_number' => '1971',
                    'operating_carrier' => ['iata_code' => 'TK'],
                    'operating_carrier_flight_number' => '1971',
                    'departing_at' => '2026-12-15T08:30:00',
                    'arriving_at' => '2026-12-15T10:45:00',
                    'origin' => ['iata_code' => 'IST', 'time_zone' => 'Europe/Istanbul'],
                    'destination' => ['iata_code' => 'LHR', 'time_zone' => 'Europe/London'],
                    'passengers' => [['passenger_id' => 'pas_1', 'baggages' => $baggages ?? [['type' => 'checked', 'quantity' => 1]]]],
                ]],
            ]],
        ];
    }
}
