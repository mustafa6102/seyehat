<?php

namespace Tests\Feature;

use App\Modules\Accommodation\Application\AccommodationSearchService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use App\Modules\Api\Infrastructure\Persistence\DatabaseTripRequestRepository;
use App\Modules\Authorization\Contracts\WebPrincipalResolver;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FixedBearerAuthenticator;
use Tests\Fakes\FixedWebPrincipalResolver;
use Tests\TestCase;

/** ACC-001 / AC-ACC-001-04..10 / TS-ACC-001-04..10 production-flow regressions. */
final class AccommodationFlowTest extends TestCase
{
    private string $owned;

    private FixedWebPrincipalResolver $web;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Queue::fake();
        Cache::flush();

        $principal = (new FixedBearerAuthenticator)->principal;
        $this->web = new FixedWebPrincipalResolver($principal);
        $this->app->instance(WebPrincipalResolver::class, $this->web);
        $this->owned = (new DatabaseTripRequestRepository(DB::connection()))
            ->save($this->tripRequest()->ownedBy($principal))->id;
    }

    #[Test]
    public function exact_round_trip_criteria_come_only_from_the_persisted_trip_request(): void
    {
        $criteria = app(AccommodationSearchService::class)->criteria(
            $this->owned,
            $this->tripRequestView('ROUND_TRIP', ['type' => 'EXACT', 'date' => '2026-12-01'], ['type' => 'EXACT', 'date' => '2026-12-05']),
            new DateTimeImmutable('2026-10-29T12:00:00+03:00'),
        );

        $this->assertIsArray($criteria);
        $this->assertSame('2026-12-01', $criteria['check_in_date']);
        $this->assertSame('2026-12-05', $criteria['check_out_date']);
        $this->assertSame(2, $criteria['adults']);
        $this->assertSame(1, $criteria['rooms']);
        $this->assertSame(['latitude' => 41.9028, 'longitude' => 12.4964], $criteria['destination_coordinates']);
    }

    #[Test]
    public function one_way_journey_fails_closed_without_inventing_a_checkout_date(): void
    {
        $result = app(AccommodationSearchService::class)->criteria(
            $this->owned,
            $this->tripRequestView('ONE_WAY', ['type' => 'EXACT', 'date' => '2026-12-01'], null),
            new DateTimeImmutable('2026-10-29T12:00:00+03:00'),
        );

        $this->assertSame('Konaklama araması yalnız gidiş-dönüş yolculuklarda yapılabilir; tek yönde çıkış tarihi bilinmiyor.', $result);
    }

    #[Test]
    public function owner_starts_one_idempotent_search_and_the_browser_cannot_supply_criteria(): void
    {
        $response = $this->withToken(FixedBearerAuthenticator::TOKEN)
            ->postJson('/api/v1/accommodation-search-jobs', ['trip_request_id' => $this->owned], ['Idempotency-Key' => 'acc-search-1'])
            ->assertAccepted()
            ->assertJsonPath('status', 'QUEUED');

        $searchId = $response->json('search_id');
        $this->withToken(FixedBearerAuthenticator::TOKEN)
            ->postJson('/api/v1/accommodation-search-jobs', ['trip_request_id' => $this->owned], ['Idempotency-Key' => 'acc-search-1'])
            ->assertAccepted()
            ->assertJsonPath('search_id', $searchId);
        $this->assertDatabaseCount('accommodation_search_jobs', 1);

        $this->withToken(FixedBearerAuthenticator::TOKEN)
            ->postJson('/api/v1/accommodation-search-jobs', [
                'trip_request_id' => $this->owned,
                'check_out_date' => '2099-01-01',
            ], ['Idempotency-Key' => 'forged-criteria'])
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED');
        $this->assertDatabaseCount('accommodation_search_jobs', 1);
    }

    #[Test]
    public function current_canonical_offer_is_persisted_once_and_returned_only_to_the_owner(): void
    {
        $offer = $this->offer();
        Cache::put('accommodation-offer:'.hash('sha256', $offer['offer_id']), $offer, new DateTimeImmutable($offer['expires_at']));
        DB::table('accommodation_search_jobs')->insert([
            'search_id' => '11111111-1111-4111-8111-111111111111',
            'idempotency_key' => 'completed-search',
            'trip_request_id' => $this->owned,
            'status' => 'COMPLETED',
            'result_json' => json_encode(['offers' => [$offer]], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'offer_id' => $offer['offer_id'],
            'expected_verified_at' => $offer['source']['verified_at'],
            'accept_changed_offer' => false,
        ];
        $this->withToken(FixedBearerAuthenticator::TOKEN)
            ->putJson("/api/v1/trip-requests/{$this->owned}/accommodation-selection", $payload, ['Idempotency-Key' => 'select-stay-1'])
            ->assertOk()
            ->assertJsonPath('status', 'CURRENT')
            ->assertJsonPath('offer.property_name', 'Roma Merkez Oteli')
            ->assertJsonPath('verified_price', null);
        $this->assertDatabaseCount('trip_request_accommodation_selections', 1);
        $this->assertDatabaseHas('trip_request_accommodation_selections', [
            'trip_request_id' => $this->owned,
            'verified_price_minor_units' => null,
            'verified_price_currency' => null,
        ]);

        $this->withHeader('Authorization', '')
            ->getJson("/api/v1/trip-requests/{$this->owned}/accommodation-selection")
            ->assertUnauthorized();
        $this->withToken(FixedBearerAuthenticator::TOKEN)
            ->getJson("/api/v1/trip-requests/{$this->owned}/accommodation-selection")
            ->assertOk()
            ->assertJsonPath('offer.property_name', 'Roma Merkez Oteli');
    }

    #[Test]
    public function trip_plan_shows_property_and_unknown_total_without_visible_provider_identifiers(): void
    {
        $offer = $this->offer();
        DB::table('trip_request_accommodation_selections')->insert([
            'trip_request_id' => $this->owned,
            'status' => 'CURRENT',
            'offer_snapshot' => json_encode($offer, JSON_THROW_ON_ERROR),
            'provider_id' => $offer['source']['provider_id'],
            'provider_offer_id' => $offer['source']['provider_offer_id'],
            'verified_price_minor_units' => null,
            'verified_price_currency' => null,
            'verified_at' => '2026-10-29 09:00:00',
            'expires_at' => '2027-01-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get("/planlar/{$this->owned}/plan")
            ->assertOk()
            ->assertSee('Roma Merkez Oteli')
            ->assertSee('Toplam fiyat kesinleşmedi')
            ->assertSee('İptal koşulu bilinmiyor')
            ->assertDontSee('duffel_stays')
            ->assertDontSee('rate-secret-42')
            ->assertDontSee('property-secret-17');
    }

    private function tripRequest(): TripRequest
    {
        return TripRequest::draft([
            'origin_place' => ['kind' => 'city', 'value' => 'Istanbul', 'iana_timezone' => 'Europe/Istanbul'],
            'destination_place' => [
                'kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome',
                'coordinates' => ['latitude' => 41.9028, 'longitude' => 12.4964],
            ],
            'journey_type' => 'ROUND_TRIP',
            'outbound_date' => ['type' => 'EXACT', 'date' => '2026-12-01'],
            'return_date' => ['type' => 'EXACT', 'date' => '2026-12-05'],
            'traveler_count' => 2,
            'passenger_composition' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            'room_count' => 1,
        ])->normalize();
    }

    private function tripRequestView(string $journeyType, array $outbound, ?array $return): TripRequestView
    {
        return new TripRequestView(
            'SEARCHABLE',
            ['kind' => 'city', 'value' => 'Istanbul', 'iana_timezone' => 'Europe/Istanbul'],
            [
                'kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome',
                'coordinates' => ['latitude' => 41.9028, 'longitude' => 12.4964],
            ],
            null,
            2,
            null,
            $journeyType,
            $outbound,
            $return,
            ['adults' => 2, 'children' => 0, 'infants' => 0],
            1,
        );
    }

    /** @return array<string, mixed> */
    private function offer(): array
    {
        return [
            'offer_id' => 'offer-canonical-1',
            'property_id' => 'property-secret-17',
            'property_name' => 'Roma Merkez Oteli',
            'location' => ['address' => 'Roma, İtalya'],
            'check_in_date' => '2026-12-01',
            'check_out_date' => '2026-12-05',
            'room_description' => 'Standart oda',
            'total_price' => [
                'value' => null,
                'known_lower_bound' => ['minor_units' => 50000, 'currency' => 'EUR'],
                'currency' => 'EUR',
                'completeness' => 'PARTIAL',
                'unknown_components' => ['MANDATORY_FEES_AT_PROPERTY'],
                'certainty' => 'ESTIMATED',
            ],
            'cancellation' => ['policy' => 'UNKNOWN', 'verification_status' => 'UNVERIFIED'],
            'check_in' => ['from_time' => '15:00', 'verification_status' => 'UNVERIFIED'],
            'state' => 'CURRENT',
            'expires_at' => '2027-01-01T00:00:00+00:00',
            'source' => [
                'provider_id' => 'duffel_stays',
                'provider_offer_id' => 'rate-secret-42',
                'fetched_at' => '2026-10-29T08:55:00+00:00',
                'verified_at' => '2026-10-29T09:00:00+00:00',
            ],
        ];
    }
}
