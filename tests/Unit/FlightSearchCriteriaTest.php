<?php

namespace Tests\Unit;

use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\UnsupportedFlightSearchDate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\StaticTripRequestService;

final class FlightSearchCriteriaTest extends TestCase
{
    private const PLACE = ['kind' => 'airport', 'value' => 'geonames:6299784', 'iana_timezone' => 'Europe/Istanbul'];

    private const EXACT = ['type' => 'EXACT', 'date' => '2026-10-15'];

    private const FLEXIBLE = ['type' => 'FLEXIBLE', 'start_date' => '2026-10-15', 'end_date' => '2026-10-20'];

    #[Test]
    public function searchable_trip_request_becomes_untransformed_criteria_without_preferences(): void
    {
        $tripRequest = (new StaticTripRequestService)->get('trip-1');

        $criteria = FlightSearchCriteria::fromSearchableTripRequest('trip-1', $tripRequest);

        self::assertSame(['trip_request_id', 'origin_place', 'destination_place', 'journey_type', 'outbound_date', 'return_date', 'traveler_count', 'passenger_composition'], array_keys($criteria->toContract()));
        self::assertSame($tripRequest->originPlace, $criteria->originPlace);
        self::assertSame($tripRequest->destinationPlace, $criteria->destinationPlace);
        self::assertSame('ROUND_TRIP', $criteria->journeyType);
        self::assertSame(self::FLEXIBLE, $criteria->outboundDate);
        self::assertSame(['type' => 'EXACT', 'date' => '2026-10-27'], $criteria->returnDate);
        self::assertSame(2, $criteria->travelerCount);
        self::assertNull($criteria->passengerComposition);
        self::assertStringNotContainsString('pace_preference', json_encode($criteria->toContract(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function passenger_composition_is_carried_without_creating_traveler_semantics(): void
    {
        $view = new TripRequestView(
            'SEARCHABLE', self::PLACE, self::PLACE, null, 3, null, 'ONE_WAY', self::EXACT, null,
            ['adults' => 2, 'children' => 1, 'infants' => 0],
        );

        $criteria = FlightSearchCriteria::fromSearchableTripRequest('trip-passengers', $view);

        self::assertSame(['adults' => 2, 'children' => 1, 'infants' => 0], $criteria->passengerComposition);
        self::assertSame($criteria->passengerComposition, $criteria->toContract()['passenger_composition']);
    }

    #[Test]
    public function one_way_outbound_is_preserved_exactly_and_has_no_return_date(): void
    {
        foreach ([self::EXACT, self::FLEXIBLE] as $outbound) {
            $criteria = $this->criteria('ONE_WAY', $outbound);

            self::assertSame('ONE_WAY', $criteria->journeyType);
            self::assertSame($outbound, $criteria->outboundDate);
            self::assertNull($criteria->returnDate);
            self::assertArrayNotHasKey('return_date', $criteria->toContract());
        }
    }

    #[Test]
    public function round_trip_exact_and_flexible_combinations_are_preserved_exactly(): void
    {
        $return = ['type' => 'FLEXIBLE', 'start_date' => '2026-10-25', 'end_date' => '2026-10-30'];
        foreach ([[self::EXACT, self::EXACT], [self::EXACT, $return], [self::FLEXIBLE, self::EXACT], [self::FLEXIBLE, $return]] as [$outbound, $returnDate]) {
            $contract = $this->criteria('ROUND_TRIP', $outbound, $returnDate)->toContract();

            self::assertSame(['ROUND_TRIP', $outbound, $returnDate], [$contract['journey_type'], $contract['outbound_date'], $contract['return_date']]);
        }

        // Inclusive bounds: a return ending on the outbound's earliest day is valid and nothing is narrowed.
        $boundary = $this->criteria('ROUND_TRIP', self::FLEXIBLE, ['type' => 'FLEXIBLE', 'start_date' => '2026-10-10', 'end_date' => '2026-10-15']);
        self::assertSame(['type' => 'FLEXIBLE', 'start_date' => '2026-10-10', 'end_date' => '2026-10-15'], $boundary->returnDate);
        self::assertSame(self::FLEXIBLE, $boundary->outboundDate);
    }

    #[Test]
    public function invalid_journeys_yield_no_criteria(): void
    {
        $invalid = [
            'ROUND_TRIP without return' => ['ROUND_TRIP', self::EXACT, null],
            'ONE_WAY with return' => ['ONE_WAY', self::EXACT, self::EXACT],
            'unknown journey type' => ['MULTI_CITY', self::EXACT, null],
            'missing outbound' => ['ONE_WAY', null, null],
            'impossible date' => ['ONE_WAY', ['type' => 'EXACT', 'date' => '2026-02-30'], null],
            'non ISO date' => ['ONE_WAY', ['type' => 'EXACT', 'date' => '15.10.2026'], null],
            'reversed range' => ['ONE_WAY', ['type' => 'FLEXIBLE', 'start_date' => '2026-10-20', 'end_date' => '2026-10-15'], null],
            'unexpected field' => ['ONE_WAY', ['type' => 'EXACT', 'date' => '2026-10-15', 'return_date' => '2026-10-20'], null],
            'legacy shape as outbound' => ['ONE_WAY', ['exact_date' => '2026-10-15'], null],
            'return before outbound' => ['ROUND_TRIP', self::FLEXIBLE, ['type' => 'EXACT', 'date' => '2026-10-14']],
        ];

        foreach ($invalid as $name => [$journeyType, $outbound, $return]) {
            try {
                $this->criteria($journeyType, $outbound, $return);
                self::fail("Yolculuk kriteri reddedilmeliydi: {$name}");
            } catch (UnsupportedFlightSearchDate $exception) {
                self::assertStringContainsString('yolculuk tipi veya tarih bilgisi uçuş araması için kullanılabilir biçimde değil', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function legacy_date_window_is_never_reinterpreted_as_a_journey(): void
    {
        $this->expectException(UnsupportedFlightSearchDate::class);
        $this->expectExceptionMessage('tarihin gidiş tarihi mi yoksa seyahat süresi mi olduğu bilinmediğinden uçuş araması yapılamaz');

        FlightSearchCriteria::fromSearchableTripRequest('trip-legacy', (new StaticTripRequestService)->get('trip-legacy'));
    }

    #[Test]
    public function station_and_locations_without_aviation_identity_are_never_converted(): void
    {
        $place = fn (string $kind, string $value): array => ['kind' => $kind, 'value' => $value, 'iana_timezone' => 'Europe/Istanbul'];
        $criteria = fn (array $origin, array $destination): FlightSearchCriteria => FlightSearchCriteria::fromSearchableTripRequest(
            'trip-9',
            new TripRequestView('SEARCHABLE', $origin, $destination, null, 1, null, 'ONE_WAY', self::EXACT),
        );

        $station = $criteria($place('station', 'geonames:7874047'), $place('airport', 'geonames:6299784'));
        $airport = $criteria($place('airport', 'geonames:6299784'), $place('city', 'geonames:745044'));

        self::assertSame('İstasyon konumları uçuş aramasında kullanılamaz.', $station->locationBlockerTr());
        self::assertSame('Uçuş araması için başlangıç ve varış konumlarının havalimanı veya şehir aramasından seçilmiş olması gerekir; bu seyahat talebindeki konumların havacılık kimliği (IATA kodu) yok.', $airport->locationBlockerTr());
        self::assertSame($place('airport', 'geonames:6299784'), $airport->originPlace);
        self::assertSame($place('city', 'geonames:745044'), $airport->destinationPlace);
        self::assertSame(['kind', 'value', 'iana_timezone'], array_keys($airport->toContract()['origin_place']));
    }

    #[Test]
    public function draft_trip_request_yields_no_criteria(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FlightSearchCriteria::fromSearchableTripRequest('trip-draft', (new StaticTripRequestService)->get('trip-draft'));
    }

    /**
     * @param  array<string, string>|null  $outbound
     * @param  array<string, string>|null  $return
     */
    private function criteria(string $journeyType, ?array $outbound, ?array $return = null): FlightSearchCriteria
    {
        return FlightSearchCriteria::fromSearchableTripRequest('trip-9', new TripRequestView('SEARCHABLE', self::PLACE, self::PLACE, null, 1, null, $journeyType, $outbound, $return));
    }
}
