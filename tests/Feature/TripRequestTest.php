<?php

namespace Tests\Feature;

use App\Modules\Api\Application\TripRequestApplicationService;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Domain\TripRequest\LocationRef;
use App\Modules\Api\Domain\TripRequest\TripRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryTripRequestRepository;
use Tests\TestCase;

final class TripRequestTest extends TestCase
{
    private const EXACT = ['type' => 'EXACT', 'date' => '2026-10-15'];

    private const FLEXIBLE = ['type' => 'FLEXIBLE', 'start_date' => '2026-10-15', 'end_date' => '2026-10-20'];

    private InMemoryTripRequestRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemoryTripRequestRepository;
        $this->app->instance(
            TripRequestService::class,
            new TripRequestApplicationService($this->repository),
        );
        // The real ownership guard reads the same repository (OP-getTripRequest, v3.16 PLT-AUTH-001).
        $this->app->instance(\App\Modules\Api\Domain\TripRequest\TripRequestRepository::class, $this->repository);    // OP-createTripRequest requires bearerAuth (v3.16); TestCase binds FixedBearerAuthenticator.
        $this->withToken(\Tests\Fakes\FixedBearerAuthenticator::TOKEN);
    }

    #[Test]
    public function ts_trip_001_01_one_way_exact_request_is_normalized_and_retrievable(): void
    {
        $payload = $this->validPayload('ONE_WAY', self::EXACT);
        $payload['preferences'] = ['pace_preference' => 'balanced'];

        $response = $this->postJson('/api/v1/trip-requests', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'SEARCHABLE')
            ->assertJsonPath('journey_type', 'ONE_WAY')
            ->assertJsonPath('outbound_date', self::EXACT)
            ->assertJsonPath('return_date', null)
            ->assertJsonPath('date_window', null)
            ->assertJsonPath('preferences.pace_preference', 'balanced');

        $location = $response->headers->get('Location');
        self::assertNotNull($location);

        $this->getJson($location)
            ->assertOk()
            ->assertExactJson(array_replace($payload, ['status' => 'SEARCHABLE', 'return_date' => null, 'date_window' => null, 'passenger_composition' => null]));

        $stored = $this->repository->all()[0];
        self::assertSame('SEARCHABLE', $stored->normalize()->normalize()->status);
    }

    #[Test]
    public function ts_trip_001_02_one_way_flexible_outbound_is_preserved_separately(): void
    {
        $payload = $this->validPayload('ONE_WAY', self::FLEXIBLE);
        $payload['origin_place']['kind'] = 'station';
        $payload['destination_place']['kind'] = 'supported_location';

        $this->postJson('/api/v1/trip-requests', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'SEARCHABLE')
            ->assertJsonPath('outbound_date', self::FLEXIBLE);
    }

    #[Test]
    public function ts_trip_001_05_round_trip_accepts_every_exact_and_flexible_combination(): void
    {
        $combinations = [
            'EXACT -> EXACT' => [self::EXACT, ['type' => 'EXACT', 'date' => '2026-10-22']],
            'EXACT -> FLEXIBLE' => [self::EXACT, ['type' => 'FLEXIBLE', 'start_date' => '2026-10-20', 'end_date' => '2026-10-25']],
            'FLEXIBLE -> EXACT' => [self::FLEXIBLE, ['type' => 'EXACT', 'date' => '2026-10-27']],
            'FLEXIBLE -> FLEXIBLE' => [self::FLEXIBLE, ['type' => 'FLEXIBLE', 'start_date' => '2026-10-25', 'end_date' => '2026-10-30']],
            // Inclusive bounds: a return on the outbound's earliest day, and overlapping ranges, are valid.
            'same day' => [self::EXACT, self::EXACT],
            'overlap' => [self::FLEXIBLE, ['type' => 'FLEXIBLE', 'start_date' => '2026-10-10', 'end_date' => '2026-10-15']],
        ];

        foreach ($combinations as $name => [$outbound, $return]) {
            $this->postJson('/api/v1/trip-requests', $this->validPayload('ROUND_TRIP', $outbound, $return))
                ->assertOk()
                ->assertJsonPath('status', 'SEARCHABLE')
                ->assertJsonPath('journey_type', 'ROUND_TRIP')
                ->assertJsonPath('outbound_date', $outbound)
                ->assertJsonPath('return_date', $return);
        }
        self::assertCount(count($combinations), $this->repository->all());
    }

    #[Test]
    public function ts_trip_001_06_invalid_journey_and_date_combinations_are_rejected_and_not_stored(): void
    {
        $cases = [
            'ONE_WAY with return_date' => [$this->validPayload('ONE_WAY', self::EXACT, self::EXACT), 'return_date'],
            'return_date without journey type' => [array_replace($this->validPayload('ONE_WAY', self::EXACT, self::EXACT), ['journey_type' => null]), 'return_date'],
            'invalid journey_type' => [$this->validPayload('MULTI_CITY', self::EXACT), 'journey_type'],
            'lowercase journey_type' => [$this->validPayload('one_way', self::EXACT), 'journey_type'],
            'invalid ISO date' => [$this->validPayload('ONE_WAY', ['type' => 'EXACT', 'date' => '15.10.2026']), 'outbound_date'],
            'impossible calendar date' => [$this->validPayload('ONE_WAY', ['type' => 'EXACT', 'date' => '2026-02-30']), 'outbound_date'],
            'reversed FLEXIBLE range' => [$this->validPayload('ONE_WAY', ['type' => 'FLEXIBLE', 'start_date' => '2026-10-20', 'end_date' => '2026-10-15']), 'outbound_date'],
            'unexpected date field' => [$this->validPayload('ONE_WAY', ['type' => 'EXACT', 'date' => '2026-10-15', 'end_date' => '2026-10-20']), 'outbound_date'],
            'unknown date type' => [$this->validPayload('ONE_WAY', ['type' => 'ANY', 'date' => '2026-10-15']), 'outbound_date'],
            'return entirely before outbound' => [$this->validPayload('ROUND_TRIP', self::FLEXIBLE, ['type' => 'FLEXIBLE', 'start_date' => '2026-10-10', 'end_date' => '2026-10-14']), 'return_date'],
            'return exact before outbound' => [$this->validPayload('ROUND_TRIP', self::EXACT, ['type' => 'EXACT', 'date' => '2026-10-14']), 'return_date'],
        ];

        foreach ($cases as $name => [$payload, $field]) {
            $this->postJson('/api/v1/trip-requests', $payload)
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED')
                ->assertJsonPath('error.message_tr', 'İstek sözleşme doğrulamasından geçemedi.')
                ->assertJsonPath('error.details.fields', [$field]);
        }

        // The legacy date_window is read-only in v3.9 and unknown top-level date fields are rejected.
        foreach (['date_window' => ['exact_date' => '2026-10-15'], 'departure_date' => '2026-10-15'] as $field => $value) {
            $this->postJson('/api/v1/trip-requests', $this->validPayload('ONE_WAY', self::EXACT) + [$field => $value])
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED');
        }
        self::assertSame([], $this->repository->all());
    }

    #[Test]
    public function ts_trip_001_03_missing_required_field_remains_draft_and_returns_turkish_error(): void
    {
        foreach (['origin_place', 'destination_place', 'journey_type', 'outbound_date', 'traveler_count'] as $missingField) {
            $payload = $this->validPayload('ONE_WAY', self::EXACT);
            unset($payload[$missingField]);

            $this->postJson('/api/v1/trip-requests', $payload)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'TRIP_REQUEST_INCOMPLETE')
                ->assertJsonPath('error.message_tr', 'Seyahat talebinin zorunlu alanları eksik.')
                ->assertJsonPath('error.details.missing_fields', [$missingField]);
        }

        // ROUND_TRIP without return_date is not searchable; the journey type is never downgraded to ONE_WAY.
        $this->postJson('/api/v1/trip-requests', $this->validPayload('ROUND_TRIP', self::EXACT))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.missing_fields', ['return_date']);

        foreach ($this->repository->all() as $tripRequest) {
            self::assertSame('DRAFT', $tripRequest->status);
        }
        self::assertSame('ROUND_TRIP', $this->repository->all()[5]->journeyType);
    }

    #[Test]
    public function ts_trip_001_04_origin_and_destination_iana_timezones_are_preserved(): void
    {
        $payload = $this->validPayload('ONE_WAY', self::EXACT);
        $payload['origin_place']['iana_timezone'] = 'Europe/Istanbul';
        $payload['destination_place']['iana_timezone'] = 'Asia/Tokyo';

        $this->postJson('/api/v1/trip-requests', $payload)
            ->assertOk()
            ->assertJsonPath('origin_place.iana_timezone', 'Europe/Istanbul')
            ->assertJsonPath('destination_place.iana_timezone', 'Asia/Tokyo');
    }

    #[Test]
    public function flight_search_passenger_composition_is_optional_but_must_match_the_total_when_present(): void
    {
        $payload = $this->validPayload('ONE_WAY', self::EXACT);
        $payload['passenger_composition'] = ['adults' => 1, 'children' => 1, 'infants' => 0];

        $this->postJson('/api/v1/trip-requests', $payload)
            ->assertOk()
            ->assertJsonPath('traveler_count', 2)
            ->assertJsonPath('passenger_composition', $payload['passenger_composition']);

        $payload['passenger_composition'] = ['adults' => 1, 'children' => 0, 'infants' => 0];
        $this->postJson('/api/v1/trip-requests', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEMA_VALIDATION_FAILED')
            ->assertJsonPath('error.details.fields', ['passenger_composition']);
    }

    #[Test]
    public function legacy_count_only_trip_request_keeps_a_null_passenger_composition(): void
    {
        $this->postJson('/api/v1/trip-requests', $this->validPayload('ONE_WAY', self::EXACT))
            ->assertOk()
            ->assertJsonPath('passenger_composition', null);
    }

    #[Test]
    public function passenger_composition_object_key_order_has_no_semantic_meaning(): void
    {
        $payload = $this->validPayload('ONE_WAY', self::EXACT);
        $payload['passenger_composition'] = ['infants' => 0, 'children' => 1, 'adults' => 1];

        $this->postJson('/api/v1/trip-requests', $payload)
            ->assertOk()
            ->assertJsonPath('passenger_composition', ['adults' => 1, 'children' => 1, 'infants' => 0]);
    }

    #[Test]
    public function legacy_date_window_record_is_returned_unchanged_without_journey_semantics(): void
    {
        $place = LocationRef::fromNullable(['kind' => 'city', 'value' => 'Istanbul', 'iana_timezone' => 'Europe/Istanbul']);
        $this->repository->save((new TripRequest(null, 'SEARCHABLE', $place, $place, ['start_date' => '2026-10-15', 'end_date' => '2026-10-20'], 2, null))
            ->ownedBy((new \Tests\Fakes\FixedBearerAuthenticator)->principal));

        $this->getJson('/api/v1/trip-requests/1')
            ->assertOk()
            ->assertJsonPath('status', 'SEARCHABLE')
            ->assertJsonPath('date_window', ['start_date' => '2026-10-15', 'end_date' => '2026-10-20'])
            ->assertJsonPath('journey_type', null)
            ->assertJsonPath('outbound_date', null)
            ->assertJsonPath('return_date', null);
        self::assertTrue($this->app->make(TripRequestService::class)->get('1')?->isLegacyDateModel());
    }

    /**
     * @param  array<string, string>  $outbound
     * @param  array<string, string>|null  $return
     * @return array<string, mixed>
     */
    private function validPayload(string $journeyType, array $outbound, ?array $return = null): array
    {
        return [
            'status' => 'DRAFT',
            'origin_place' => [
                'kind' => 'city',
                'value' => 'Istanbul',
                'iana_timezone' => 'Europe/Istanbul',
            ],
            'destination_place' => [
                'kind' => 'airport',
                'value' => 'NRT',
                'iana_timezone' => 'Asia/Tokyo',
            ],
            'journey_type' => $journeyType,
            'outbound_date' => $outbound,
        ] + ($return === null ? [] : ['return_date' => $return]) + [
            'traveler_count' => 2,
            'preferences' => null,
        ];
    }
}
