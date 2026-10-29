<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\FlightSearch\Application\FlightSelectionRejected;
use App\Modules\FlightSearch\Application\FlightSelectionService;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightSegment;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\FlightSearch\Domain\ProviderSource;
use App\Modules\FlightSearch\Domain\SegmentTime;
use App\Modules\FlightSearch\Infrastructure\Persistence\DatabaseFlightSearchJobRepository;
use App\Modules\FlightSearch\Infrastructure\Persistence\DatabaseFlightSelectionRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryFlightOfferRepository;
use Tests\TestCase;

/**
 * v3.23 OD-022: selection of round-trip offers (RULE-FLT-REPRICE-001-08, TS-FLT-REPRICE-001-15), the D2 legacy read of
 * every place a v3.4 offer may already be stored, and the FR-FLT-002-04 ordering invariant.
 */
final class RoundTripFlightOfferTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('trip_request_flight_selections', function ($table): void {
            $table->string('trip_request_id', 255)->primary();
            $table->string('status', 32);
            $table->longText('offer_snapshot');
            $table->string('provider_id', 64);
            $table->string('provider_offer_id', 255);
            $table->bigInteger('verified_price_minor_units')->nullable();
            $table->char('verified_price_currency', 3)->nullable();
            $table->dateTime('verified_at');
            $table->dateTime('expires_at');
            $table->timestamps();
        });
        $this->now = new DateTimeImmutable('now');
    }

    #[Test]
    public function ts_flt_reprice_001_15_a_round_trip_offer_is_selectable_only_for_a_round_trip_request(): void
    {
        $offer = $this->roundTrip();
        $this->searchResultFor('trip-rt', $offer);
        $this->searchResultFor('trip-ow', $offer);
        $selections = $this->selections();
        $selections->rememberVerification($offer, 'CURRENT', $this->now);
        $service = $this->service($offer, ['trip-rt' => 'ROUND_TRIP', 'trip-ow' => 'ONE_WAY']);

        try {
            $service->select('trip-ow', 'off_rt', $this->now->format(DATE_ATOM), false);
            self::fail('A ROUND_TRIP offer must not be selectable for a ONE_WAY TripRequest.');
        } catch (FlightSelectionRejected $rejected) {
            self::assertSame(['OFFER_NOT_SELECTABLE', 409], [$rejected->machineCode, $rejected->httpStatus]);
        }
        self::assertNull($selections->find('trip-ow'));

        $selected = $service->select('trip-rt', 'off_rt', $this->now->format(DATE_ATOM), false);
        self::assertSame('CURRENT', $selected['status']);
        self::assertSame('ROUND_TRIP', $selected['offer']['journey_type']);
        self::assertSame(['OUTBOUND', 'INBOUND'], array_column($selected['offer']['segments'], 'direction'));
        // One verified total for the whole journey (RULE-FLT-REPRICE-001-07, A3).
        self::assertSame(['minor_units' => 41000, 'currency' => 'EUR'], $selected['verified_price']);
    }

    #[Test]
    public function d2_a_stored_v3_4_selection_snapshot_reads_as_one_way_outbound_without_rewriting_the_row(): void
    {
        $legacy = $this->oneWay()->toContract($this->now);
        unset($legacy['journey_type'], $legacy['segments'][0]['direction']);
        $raw = json_encode($legacy, JSON_THROW_ON_ERROR);
        DB::table('trip_request_flight_selections')->insert([
            'trip_request_id' => 'trip-legacy', 'status' => 'CURRENT', 'offer_snapshot' => $raw, 'provider_id' => 'duffel_flights',
            'provider_offer_id' => 'off_ow', 'verified_price_minor_units' => 12500, 'verified_price_currency' => 'EUR',
            'verified_at' => '2026-10-01 10:00:00', 'expires_at' => '2099-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $offer = $this->selections()->find('trip-legacy')['offer'];

        self::assertSame(['provider_offer_id', 'journey_type'], array_slice(array_keys($offer), 0, 2));
        self::assertSame('ONE_WAY', $offer['journey_type']);
        self::assertSame('OUTBOUND', $offer['segments'][0]['direction']);
        unset($offer['journey_type'], $offer['segments'][0]['direction']);
        self::assertEquals($legacy, $offer, 'Nothing but the two v3.23 fields may change.');
        self::assertSame($raw, DB::table('trip_request_flight_selections')->value('offer_snapshot'));
    }

    #[Test]
    public function d2_a_stored_v3_4_search_result_reads_as_one_way_outbound(): void
    {
        $legacy = $this->oneWay()->toContract($this->now);
        unset($legacy['journey_type'], $legacy['segments'][0]['direction']);
        DB::table('flight_search_jobs')->insert([
            'search_id' => '11111111-1111-4111-8111-111111111111', 'idempotency_key' => 'legacy-job', 'trip_request_id' => 'trip-legacy',
            'status' => 'COMPLETED', 'result_json' => json_encode(['offers' => [$legacy], 'ranking' => null], JSON_THROW_ON_ERROR),
            'error_code' => null, 'error_message_tr' => null, 'expires_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = (new DatabaseFlightSearchJobRepository(DB::connection()))->find('11111111-1111-4111-8111-111111111111')->result;

        self::assertSame('ONE_WAY', $result['offers'][0]['journey_type']);
        self::assertSame('OUTBOUND', $result['offers'][0]['segments'][0]['direction']);
        self::assertNull($result['ranking']);
    }

    #[Test]
    public function d2_an_offer_cached_before_v3_23_unserializes_as_one_way_outbound(): void
    {
        $serialized = serialize($this->oneWay());
        // A pre-v3.23 FlightSegment has no direction property at all.
        $legacy = (string) preg_replace_callback(
            '/(O:\d+:"App\\\\Modules\\\\FlightSearch\\\\Domain\\\\FlightSegment":)(\d+)(:\{)s:9:"direction";s:8:"OUTBOUND";/',
            fn (array $m): string => $m[1].((int) $m[2] - 1).$m[3],
            $serialized,
        );
        self::assertNotSame($serialized, $legacy);
        self::assertStringNotContainsString('"direction"', $legacy);

        $offer = unserialize($legacy);

        self::assertInstanceOf(FlightOffer::class, $offer);
        self::assertSame('ONE_WAY', $offer->journeyType());
        self::assertSame('OUTBOUND', $offer->toContract($this->now)['segments'][0]['direction']);
    }

    #[Test]
    public function fr_flt_002_04_segments_must_be_outbound_first_then_inbound(): void
    {
        foreach ([['INBOUND', 'OUTBOUND'], ['OUTBOUND', 'INBOUND', 'OUTBOUND'], ['INBOUND']] as $directions) {
            try {
                $this->offer('off_bad', $directions);
                self::fail('Invalid direction order accepted: '.implode(',', $directions));
            } catch (InvalidArgumentException) {
                // expected
            }
        }
        self::assertSame('ROUND_TRIP', $this->offer('off_ok', ['OUTBOUND', 'OUTBOUND', 'INBOUND'])->journeyType());
        self::assertSame('ONE_WAY', $this->offer('off_ow2', ['OUTBOUND', 'OUTBOUND'])->journeyType());
    }

    /** The emitted contract matches the active SCH-FlightOffer 3.23 field sets and enums (no schema library needed). */
    #[Test]
    public function to_contract_matches_the_active_v3_23_flight_offer_schema(): void
    {
        $schema = json_decode((string) file_get_contents(base_path('contracts/v3.23/schemas/FlightOffer.schema.json')), true, flags: JSON_THROW_ON_ERROR);
        $segmentSchema = $schema['$defs']['flight_segment'];

        foreach ([$this->oneWay(), $this->roundTrip()] as $offer) {
            $contract = $offer->toContract($this->now);
            self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($contract));
            self::assertSame([], array_diff($schema['required'], array_keys($contract)));
            self::assertContains($contract['journey_type'], $schema['properties']['journey_type']['enum']);
            foreach ($contract['segments'] as $segment) {
                self::assertEqualsCanonicalizing(array_keys($segmentSchema['properties']), array_keys($segment));
                self::assertContains($segment['direction'], $segmentSchema['properties']['direction']['enum']);
            }
        }
    }

    private function selections(): DatabaseFlightSelectionRepository
    {
        return new DatabaseFlightSelectionRepository(DB::connection(), $this->app->make('cache.store'));
    }

    /** @param array<string, string> $journeys */
    private function service(FlightOffer $offer, array $journeys): FlightSelectionService
    {
        $tripRequests = new class($journeys) implements TripRequestService
        {
            /** @param array<string, string> $journeys */
            public function __construct(private readonly array $journeys) {}

            public function create(array $input, PrincipalRef $owner): CreatedTripRequest
            {
                throw new LogicException('not used');
            }

            public function get(string $id): ?TripRequestView
            {
                if (! isset($this->journeys[$id])) {
                    return null;
                }
                $place = ['kind' => 'airport', 'value' => 'duffel:x', 'iana_timezone' => 'UTC'];

                return new TripRequestView(
                    status: 'SEARCHABLE', originPlace: $place, destinationPlace: $place, dateWindow: null, travelerCount: 1,
                    preferences: null, journeyType: $this->journeys[$id], outboundDate: ['type' => 'EXACT', 'date' => '2026-10-24'],
                    returnDate: $this->journeys[$id] === 'ROUND_TRIP' ? ['type' => 'EXACT', 'date' => '2026-10-31'] : null,
                );
            }
        };

        return new FlightSelectionService($this->selections(), new InMemoryFlightOfferRepository([$offer]), $tripRequests);
    }

    private function searchResultFor(string $tripRequestId, FlightOffer $offer): void
    {
        DB::table('flight_search_jobs')->insert([
            'search_id' => substr(hash('sha256', $tripRequestId), 0, 36), 'idempotency_key' => 'job-'.$tripRequestId, 'trip_request_id' => $tripRequestId,
            'status' => 'COMPLETED', 'result_json' => json_encode(['offers' => [$offer->toContract($this->now)]], JSON_THROW_ON_ERROR),
            'error_code' => null, 'error_message_tr' => null, 'expires_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function roundTrip(): FlightOffer
    {
        return $this->offer('off_rt', ['OUTBOUND', 'INBOUND'], 41000);
    }

    private function oneWay(): FlightOffer
    {
        return $this->offer('off_ow', ['OUTBOUND'], 12500);
    }

    /** @param list<string> $directions */
    private function offer(string $id, array $directions, int $minorUnits = 10000): FlightOffer
    {
        $expiresAt = $this->now->modify('+1 day');
        $segments = [];
        foreach ($directions as $i => $direction) {
            $departure = $this->now->modify('+'.(10 + $i).' days')->setTime(9, 0)->setTimezone(new \DateTimeZone('UTC'));
            $arrival = $departure->modify('+3 hours');
            $segments[] = new FlightSegment(
                'TK', (string) (1951 + $i), [], 'UNKNOWN',
                new SegmentTime($departure->format('Y-m-d\TH:i:s'), 'UTC', $departure),
                new SegmentTime($arrival->format('Y-m-d\TH:i:s'), 'UTC', $arrival),
                $direction,
            );
        }

        return new FlightOffer(
            providerOfferId: $id,
            price: new Money($minorUnits, 'EUR'),
            priceCertainty: 'EXACT',
            priceCompleteness: 'COMPLETE',
            baggage: (new BaggageAllowance(null, null, null))->withSource('duffel_flights:'.$id, $this->now, $this->now, $expiresAt),
            expiresAt: $expiresAt,
            sources: [new ProviderSource('duffel_flights', $id, 'AGGREGATOR', 'UNKNOWN', true, false, 'FRESH', 'UNKNOWN', $this->now, $this->now, $expiresAt)],
            segments: $segments,
            bookable: false,
        );
    }
}
