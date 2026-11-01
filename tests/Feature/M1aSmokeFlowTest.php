<?php

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestOwnership;
use App\Modules\Api\Application\TripRequestApplicationService;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\FlightSearch\Application\FlightOfferAggregator;
use App\Modules\FlightSearch\Application\LocationGatedFlightSearchExecutor;
use App\Modules\FlightSearch\Application\ResilientFlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightProviderAdapter;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use App\Modules\FlightSearch\Domain\FlightSearchJobRepository;
use App\Modules\FlightSearch\Domain\FlightSegment;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\FlightSearch\Domain\ProviderSource;
use App\Modules\FlightSearch\Domain\SegmentTime;
use App\Modules\ProviderAcl\Application\CircuitBreaker;
use App\Modules\ProviderAcl\Application\ResilientProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ProviderCallGuard;
use App\Modules\ProviderAcl\Contracts\ResiliencePolicies;
use App\Modules\ProviderAcl\Domain\ProviderCapabilityRepository;
use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
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
 * M1A web smoke flow with every external system faked: aviation location search -> planner -> summary ->
 * flight search page -> async start + polling -> canonical offer -> offer page -> reprice. No real provider.
 */
final class M1aSmokeFlowTest extends TestCase
{
    private SmokeAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        // Flight flow, not authorization: the caller owns every TripRequest the bound service knows (v3.16 bearerAuth).
        $this->app->bind(TripRequestOwnership::class, ServiceOwnedTripRequests::class);
        $this->withToken(FixedBearerAuthenticator::TOKEN);

        Sleep::fake();
        config([
            'services.duffel.access_token' => 'smoke-token',
            'provider_resilience.providers.duffel_places' => [
                'version' => 'test-1',
                'timeout_ms' => 1000,
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
        Http::fake(['api.duffel.com/places/suggestions*' => function (Request $request) {
            return Http::response(['data' => $request['query'] === 'istanbul'
                ? [['type' => 'airport', 'id' => 'arp_ist_tr', 'iata_code' => 'IST', 'iata_country_code' => 'TR', 'name' => 'Istanbul Airport', 'city_name' => 'Istanbul', 'time_zone' => 'Europe/Istanbul']]
                : [['type' => 'city', 'id' => 'cit_rom_it', 'iata_code' => 'ROM', 'iata_country_code' => 'IT', 'name' => 'Rome', 'time_zone' => null, 'airports' => [['time_zone' => 'Europe/Rome']]]]]);
        }]);

        $this->app->instance(TripRequestService::class, new TripRequestApplicationService(new InMemoryTripRequestRepository));
        $this->app->instance(FlightSearchJobRepository::class, new InMemoryFlightSearchJobRepository);
        $this->app->instance(FlightOfferRepository::class, new InMemoryFlightOfferRepository);
        $this->app->instance(ProviderCapabilityRepository::class, new InMemoryProviderCapabilityRepository);
        $this->adapter = new SmokeAdapter;
        $this->app->instance(FlightSearchExecutor::class, new LocationGatedFlightSearchExecutor(
            new ResilientFlightSearchExecutor([$this->adapter], new SmokePassThroughCallGuard, new FlightOfferAggregator),
        ));
    }

    #[Test]
    public function a_traveller_can_plan_search_view_and_reprice_without_any_real_provider(): void
    {
        $this->get('/')->assertOk()->assertSee('Yolculuğunuzu planlayın')->assertSee('Gidiş-dönüş');

        $origin = $this->getJson('/konumlar/ara?baglam=ucus&q=istanbul')->assertOk()->json('locations.0');
        $destination = $this->getJson('/konumlar/ara?baglam=ucus&q=roma')->assertOk()->json('locations.0');
        self::assertSame(['IST', 'ROM'], [$origin['aviation_identity']['code'], $destination['aviation_identity']['code']]);

        $redirect = $this->post('/planla', [
            'origin_selection' => $origin['selection'],
            'destination_selection' => $destination['selection'],
            // OD-022 keeps ROUND_TRIP and FLEXIBLE provider search blocked; the searchable smoke journey is ONE_WAY + EXACT.
            'journey_type' => 'ONE_WAY',
            'outbound_date_mode' => 'exact', 'outbound_exact_date' => '2026-10-15',
            'traveler_count' => '2',
        ])->assertRedirect();
        $summaryUrl = (string) $redirect->headers->get('Location');
        $tripRequestId = basename($summaryUrl);

        $this->get($summaryUrl)->assertOk()
            ->assertSee('Uçuş aramaya hazır')->assertSee('Tek yön')->assertSee('IST')->assertSee('ROM')
            ->assertSee('Uçuşları ara');
        $this->get("/planlar/{$tripRequestId}/ucuslar")->assertOk()
            ->assertSee('data-search-start', false)
            ->assertDontSee('data-location-blocker', false)
            ->assertDontSee('data-date-blocker', false)
            ->assertDontSee('data-journey-blocker', false);

        $start = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => $tripRequestId], ['Idempotency-Key' => 'smoke-1'])->assertAccepted();
        $again = $this->postJson('/api/v1/flight-search-jobs', ['trip_request_id' => $tripRequestId], ['Idempotency-Key' => 'smoke-1'])->assertAccepted();
        self::assertSame($start->json('search_id'), $again->json('search_id'));

        $job = $this->getJson('/api/v1/flight-search-jobs/'.$start->json('search_id'))->assertOk()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('trip_request_id', $tripRequestId)
            ->assertJsonPath('result.offers.0.provider_offer_id', 'SMOKE-1')
            ->assertJsonPath('result.offers.0.price.completeness', 'COMPLETE');
        $this->getJson('/api/v1/flight-search-jobs/'.$start->json('search_id'))->assertOk()->assertJsonPath('status', 'COMPLETED');

        // Exactly one provider execution with the canonical, untransformed journey and aviation identities.
        self::assertCount(1, $this->adapter->received);
        $criteria = $this->adapter->received[0]->toContract();
        self::assertSame(['IATA_AIRPORT', 'IST'], array_values($criteria['origin_place']['aviation_identity']));
        self::assertSame(['IATA_CITY', 'ROM'], array_values($criteria['destination_place']['aviation_identity']));
        self::assertSame(['ONE_WAY', ['type' => 'EXACT', 'date' => '2026-10-15'], 2],
            [$criteria['journey_type'], $criteria['outbound_date'], $criteria['traveler_count']]);
        self::assertArrayNotHasKey('return_date', $criteria);
        self::assertStringNotContainsString('smoke-token', (string) json_encode($job->json()));

        $this->getJson('/api/v1/offers/SMOKE-1')->assertOk()->assertJsonPath('provider_offer_id', 'SMOKE-1');
        $this->get('/teklifler/SMOKE-1')->assertOk()->assertSee('data-reprice-url', false)->assertDontSee('Satın al');

        // No production reprice capability is recorded: the offer is reported unverified, never silently approved.
        $this->postJson('/api/v1/offers/SMOKE-1/reprice')->assertOk()
            ->assertJsonPath('current.offer.state', 'UNVERIFIED')
            ->assertJsonPath('previous_approval_valid', false)
            ->assertJsonPath('continuation_allowed', false);
    }
}

final class SmokeAdapter implements FlightProviderAdapter
{
    /** @var list<FlightSearchCriteria> */
    public array $received = [];

    public function providerId(): string
    {
        return 'smoke-provider';
    }

    public function search(FlightSearchCriteria $criteria, ?int $timeoutMs = null): array
    {
        $this->received[] = $criteria;
        $fetchedAt = new DateTimeImmutable('2026-09-29T10:00:00+00:00');

        return [new FlightOffer(
            providerOfferId: 'SMOKE-1',
            price: new Money(245000, 'TRY'),
            priceCertainty: 'EXACT',
            priceCompleteness: 'COMPLETE',
            baggage: new BaggageAllowance(true, null, null, sourceReference: 'smoke-provider:SMOKE-1', fetchedAt: $fetchedAt, verifiedAt: $fetchedAt),
            // Relative to the real clock (the reprice service reads "now"), so the offer is not expired on run day.
            expiresAt: new DateTimeImmutable('now +1 day'),
            sources: [new ProviderSource('smoke-provider', 'SMOKE-1', 'AGGREGATOR', 'NDC', true, false, 'FRESH', 'UNSUPPORTED', $fetchedAt, $fetchedAt, null)],
            segments: [new FlightSegment(
                'TK',
                'TK1861',
                ['TK1861'],
                'FULL_SERVICE',
                new SegmentTime('2026-10-15T08:00:00', 'Europe/Istanbul', new DateTimeImmutable('2026-10-15T05:00:00+00:00')),
                new SegmentTime('2026-10-15T09:45:00', 'Europe/Rome', new DateTimeImmutable('2026-10-15T07:45:00+00:00')),
            )],
            bookable: false,
        )];
    }
}


final class SmokePassThroughCallGuard implements ProviderCallGuard
{
    public function call(string $providerId, callable $operation): mixed
    {
        return $operation(1000);
    }
}
