<?php

namespace Tests\Feature;

use App\Modules\FlightSearch\Application\FlightOfferAggregator;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightSegment;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\FlightSearch\Domain\SegmentTime;
use App\Modules\FlightSearch\Infrastructure\Adapters\AbstractFlightProviderAdapter;
use App\Modules\ProviderAcl\Application\ProviderCapabilityMatrixService;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use App\Modules\ProviderAcl\Domain\ProviderCapability;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryProviderCapabilityRepository;
use Tests\Fakes\StaticTripRequestService;
use Tests\TestCase;

final class FlightOfferNormalizationTest extends TestCase
{
    #[Test]
    public function ts_flt_002_01_duplicate_sources_are_merged_without_exposing_raw_payload(): void
    {
        $matrix = $this->matrixWithSearchAndBook();
        $first = (new FixtureFlightProviderAdapter($matrix, 'provider-a', [$this->rawOffer('A-1')]))->search(self::criteria())[0];
        $second = (new FixtureFlightProviderAdapter($matrix, 'provider-b', [$this->rawOffer('B-1')]))->search(self::criteria())[0];

        $offers = (new FlightOfferAggregator)->aggregate([$first, $second]);
        $contract = $offers[0]->toContract(new DateTimeImmutable('2026-09-27T10:16:00+00:00'));

        self::assertCount(1, $offers);
        self::assertSame(['provider-a', 'provider-b'], array_map(
            fn ($source): string => $source->providerId,
            $offers[0]->sources,
        ));
        self::assertSame('EXPIRED', $contract['state']);
        self::assertSame(
            'EXPIRED',
            $offers[0]->sources[0]->verificationStatus(new DateTimeImmutable('2026-09-27T10:16:00+00:00')),
        );
        self::assertArrayNotHasKey('raw_payload', $contract);
        self::assertStringNotContainsString('fixture-secret', json_encode($contract, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function ts_flt_002_02_same_flight_with_different_fare_is_preserved(): void
    {
        $matrix = $this->matrixWithSearchAndBook();
        $base = (new FixtureFlightProviderAdapter($matrix, 'provider-a', [$this->rawOffer('A-1')]))->search(self::criteria())[0];
        $differentFare = (new FixtureFlightProviderAdapter($matrix, 'provider-b', [
            $this->rawOffer('B-1', priceMinorUnits: 14500),
        ]))->search(self::criteria())[0];

        self::assertCount(2, (new FlightOfferAggregator)->aggregate([$base, $differentFare]));
    }

    #[Test]
    public function ts_flt_002_03_search_only_provider_is_never_bookable(): void
    {
        $matrix = $this->matrix(search: true, book: false);
        $offer = (new FixtureFlightProviderAdapter($matrix, 'provider-a', [$this->rawOffer('A-1')]))->search(self::criteria())[0];

        self::assertFalse($offer->bookable);
        self::assertTrue($offer->sources[0]->searchable);
        self::assertFalse($offer->sources[0]->bookable);
    }

    #[Test]
    public function ts_flt_002_04_codeshares_merge_and_keep_marketing_references(): void
    {
        $matrix = $this->matrixWithSearchAndBook();
        $first = (new FixtureFlightProviderAdapter($matrix, 'provider-a', [
            $this->rawOffer('A-1', marketingCarrier: 'TK', marketingFlightNumber: 'TK1984'),
        ]))->search(self::criteria())[0];
        $second = (new FixtureFlightProviderAdapter($matrix, 'provider-b', [
            $this->rawOffer('B-1', marketingCarrier: 'LH', marketingFlightNumber: 'LH1298'),
        ]))->search(self::criteria())[0];

        $offer = (new FlightOfferAggregator)->aggregate([$first, $second])[0];

        self::assertSame(['LH1298', 'TK1984'], $offer->segments[0]->marketingFlightReferences);
    }

    #[Test]
    public function ts_flt_002_05_unverified_provider_classification_defaults_to_unknown(): void
    {
        $offer = (new FixtureFlightProviderAdapter(
            $this->matrixWithSearchAndBook(),
            'provider-a',
            [$this->rawOffer('A-1', verifiedClassifications: false)],
        ))->search(self::criteria())[0];

        self::assertSame('UNKNOWN', $offer->sources[0]->providerRole);
        self::assertSame('UNKNOWN', $offer->sources[0]->distributionChannel);
        self::assertSame('UNKNOWN', $offer->segments[0]->carrierModel);
    }

    #[Test]
    public function partial_price_uses_null_value_and_preserves_known_lower_bound(): void
    {
        $offer = (new FixtureFlightProviderAdapter($this->matrixWithSearchAndBook(), 'provider-a', [
            $this->rawOffer('A-1', priceMinorUnits: 10000, priceCompleteness: 'PARTIAL'),
        ]))->search(self::criteria())[0];

        self::assertNull($offer->toContract()['price']['value']);
        self::assertSame(
            ['minor_units' => 10000, 'currency' => 'EUR'],
            $offer->toContract()['price']['known_lower_bound'],
        );
        self::assertSame(['BASE_PRICE_PARTIAL'], $offer->toContract()['price']['unknown_components']);
    }

    #[Test]
    public function unknown_price_has_null_value_and_never_fabricates_zero(): void
    {
        $offer = (new FixtureFlightProviderAdapter($this->matrixWithSearchAndBook(), 'provider-a', [
            $this->rawOffer(
                'A-1',
                priceMinorUnits: 10000,
                priceCompleteness: 'PARTIAL',
                priceCertainty: 'UNKNOWN',
            ),
        ]))->search(self::criteria())[0];

        self::assertNull($offer->toContract()['price']['value']);
        self::assertSame(10000, $offer->toContract()['price']['known_lower_bound']['minor_units']);
        self::assertNotSame(0, $offer->toContract()['price']['known_lower_bound']['minor_units']);
    }

    #[Test]
    public function complete_price_uses_integer_minor_units_in_the_restored_contract(): void
    {
        $offer = (new FixtureFlightProviderAdapter($this->matrixWithSearchAndBook(), 'provider-a', [
            $this->rawOffer('A-1', priceMinorUnits: 12000),
        ]))->search(self::criteria())[0];

        self::assertSame([
            'provider_offer_id',
            'state',
            'price',
            'expires_at',
            'baggage',
            'bookable',
            'sources',
            'segments',
        ], array_keys($offer->toContract()));
        self::assertSame(['minor_units' => 12000, 'currency' => 'EUR'], $offer->toContract()['price']['value']);
        self::assertNull($offer->toContract()['price']['known_lower_bound']);
        self::assertSame([], $offer->toContract()['price']['unknown_components']);
    }

    #[Test]
    public function ts_flt_002_07_dst_duration_uses_utc_instants_and_preserves_local_timezones(): void
    {
        $offer = (new FixtureFlightProviderAdapter($this->matrixWithSearchAndBook(), 'provider-a', [
            $this->rawOffer(
                'A-1',
                departureLocal: '2026-10-25T02:30:00',
                departureTimezone: 'Europe/Berlin',
                departureUtc: '2026-10-25T00:30:00+00:00',
                arrivalLocal: '2026-10-25T02:30:00',
                arrivalTimezone: 'Europe/London',
                arrivalUtc: '2026-10-25T02:30:00+00:00',
            ),
        ]))->search(self::criteria())[0];

        self::assertSame(120, $offer->segments[0]->durationMinutes());
        self::assertSame('Europe/Berlin', $offer->segments[0]->departure->ianaTimezone);
        self::assertSame('Europe/London', $offer->segments[0]->arrival->ianaTimezone);
    }

    /** @return array<string, mixed> */
    #[Test]
    public function v3_4_offer_contract_exposes_canonical_segments_sources_and_bookable_without_raw_payload(): void
    {
        $offer = (new FixtureFlightProviderAdapter($this->matrix(search: true, book: false), 'provider-a', [$this->rawOffer('A-1')]))->search(self::criteria())[0];

        $contract = $offer->toContract(new DateTimeImmutable('2026-09-27T10:05:00+00:00'));

        self::assertSame(['provider_offer_id', 'state', 'price', 'expires_at', 'baggage', 'bookable', 'sources', 'segments'], array_keys($contract));
        self::assertFalse($contract['bookable']);
        self::assertSame(['provider_id', 'provider_offer_id', 'provider_role', 'distribution_channel', 'fetched_at', 'verified_at', 'expires_at', 'verification_status'], array_keys($contract['sources'][0]));
        self::assertSame(['provider-a', 'AGGREGATOR', 'NDC'], [$contract['sources'][0]['provider_id'], $contract['sources'][0]['provider_role'], $contract['sources'][0]['distribution_channel']]);
        self::assertSame([
            'operating_carrier' => 'LH',
            'operating_flight_number' => 'LH1301',
            'marketing_flight_references' => ['TK1984'],
            'carrier_model' => 'FULL_SERVICE',
            'departure' => ['local_datetime' => '2026-10-25T11:00:00', 'iana_timezone' => 'Europe/Istanbul', 'utc_instant' => '2026-10-25T08:00:00+00:00'],
            'arrival' => ['local_datetime' => '2026-10-25T12:30:00', 'iana_timezone' => 'Europe/Berlin', 'utc_instant' => '2026-10-25T11:30:00+00:00'],
            'duration_minutes' => 210,
        ], $contract['segments'][0]);
        self::assertStringNotContainsString('fixture-secret', json_encode($contract, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function ts_flt_002_06_partial_cost_offer_is_not_promoted_over_complete_cost_offer(): void
    {
        $matrix = $this->matrixWithSearchAndBook();
        $partialCheaper = (new FixtureFlightProviderAdapter($matrix, 'provider-a', [
            $this->rawOffer('A-PARTIAL', priceMinorUnits: 9000, priceCompleteness: 'PARTIAL'),
        ]))->search(self::criteria())[0];
        $complete = (new FixtureFlightProviderAdapter($matrix, 'provider-b', [
            $this->rawOffer('B-COMPLETE', priceMinorUnits: 12000),
        ]))->search(self::criteria())[0];

        $ranked = (new FlightOfferAggregator)->aggregate([$partialCheaper, $complete]);

        self::assertSame(['B-COMPLETE', 'A-PARTIAL'], array_map(fn ($offer): string => $offer->providerOfferId, $ranked));
        self::assertTrue($ranked[0]->bookable && $ranked[1]->bookable);
    }

    private static function criteria(): FlightSearchCriteria
    {
        return FlightSearchCriteria::fromSearchableTripRequest('trip-1', (new StaticTripRequestService)->get('trip-1'));
    }

    private function rawOffer(
        string $providerOfferId,
        int $priceMinorUnits = 12000,
        string $priceCompleteness = 'COMPLETE',
        string $priceCertainty = 'EXACT',
        string $marketingCarrier = 'TK',
        string $marketingFlightNumber = 'TK1984',
        bool $verifiedClassifications = true,
        string $departureLocal = '2026-10-25T11:00:00',
        string $departureTimezone = 'Europe/Istanbul',
        string $departureUtc = '2026-10-25T08:00:00+00:00',
        string $arrivalLocal = '2026-10-25T12:30:00',
        string $arrivalTimezone = 'Europe/Berlin',
        string $arrivalUtc = '2026-10-25T11:30:00+00:00',
    ): array {
        return [
            'provider_offer_id' => $providerOfferId,
            'raw_payload' => ['secret' => 'fixture-secret'],
            'provider_role' => 'AGGREGATOR',
            'distribution_channel' => 'NDC',
            'classifications_verified' => $verifiedClassifications,
            'fetched_at' => '2026-09-27T10:00:00+00:00',
            'verified_at' => '2026-09-27T10:00:00+00:00',
            'expires_at' => '2026-09-27T10:15:00+00:00',
            'price_minor_units' => $priceMinorUnits,
            'currency' => 'EUR',
            'price_completeness' => $priceCompleteness,
            'price_certainty' => $priceCertainty,
            'baggage' => ['personal_item' => true, 'cabin_bag' => null, 'checked_bag' => null],
            'segments' => [[
                'operating_carrier' => 'LH',
                'operating_flight_number' => 'LH1301',
                'marketing_carrier' => $marketingCarrier,
                'marketing_flight_number' => $marketingFlightNumber,
                'carrier_model' => 'FULL_SERVICE',
                'departure_local' => $departureLocal,
                'departure_timezone' => $departureTimezone,
                'departure_utc' => $departureUtc,
                'arrival_local' => $arrivalLocal,
                'arrival_timezone' => $arrivalTimezone,
                'arrival_utc' => $arrivalUtc,
            ]],
        ];
    }

    private function matrixWithSearchAndBook(): ProviderCapabilityMatrixService
    {
        return $this->matrix(search: true, book: true);
    }

    private function matrix(bool $search, bool $book): ProviderCapabilityMatrixService
    {
        $repository = new InMemoryProviderCapabilityRepository;
        foreach (['provider-a', 'provider-b'] as $providerId) {
            foreach (['search' => $search, 'book' => $book] as $operation => $supported) {
                $repository->save(new ProviderCapability(
                    providerId: $providerId,
                    operation: $operation,
                    environment: 'production',
                    supported: $supported,
                    status: 'FRESH',
                    evidence: $supported ? $providerId.'-'.$operation.'-evidence' : null,
                    verifiedAt: $supported ? new DateTimeImmutable('2026-09-27T10:00:00+00:00') : null,
                ));
            }
        }

        return new ProviderCapabilityMatrixService($repository);
    }
}

final class FixtureFlightProviderAdapter extends AbstractFlightProviderAdapter
{
    /** @param list<array<string, mixed>> $rawOffers */
    public function __construct(
        ProviderCapabilityMatrix $capabilityMatrix,
        string $providerId,
        private readonly array $rawOffers,
    ) {
        parent::__construct($capabilityMatrix, $providerId);
    }

    protected function fetchRawOffers(FlightSearchCriteria $criteria): iterable
    {
        return $this->rawOffers;
    }

    protected function normalizeRawOffer(mixed $raw): FlightOffer
    {
        $classificationsVerified = $raw['classifications_verified'] === true;
        $segments = array_map(
            fn (array $segment): FlightSegment => new FlightSegment(
                operatingCarrier: $segment['operating_carrier'],
                operatingFlightNumber: $segment['operating_flight_number'],
                marketingFlightReferences: [$segment['marketing_flight_number']],
                carrierModel: $classificationsVerified ? $segment['carrier_model'] : 'UNKNOWN',
                departure: new SegmentTime($segment['departure_local'], $segment['departure_timezone'], new DateTimeImmutable($segment['departure_utc'])),
                arrival: new SegmentTime($segment['arrival_local'], $segment['arrival_timezone'], new DateTimeImmutable($segment['arrival_utc'])),
            ),
            $raw['segments'],
        );

        return $this->canonicalOffer(
            providerOfferId: $raw['provider_offer_id'],
            providerRole: $classificationsVerified ? $raw['provider_role'] : null,
            distributionChannel: $classificationsVerified ? $raw['distribution_channel'] : null,
            fetchedAt: new DateTimeImmutable($raw['fetched_at']),
            verifiedAt: new DateTimeImmutable($raw['verified_at']),
            expiresAt: new DateTimeImmutable($raw['expires_at']),
            price: new Money($raw['price_minor_units'], $raw['currency']),
            priceCertainty: $raw['price_certainty'],
            priceCompleteness: $raw['price_completeness'],
            baggage: new BaggageAllowance(
                personalItem: $raw['baggage']['personal_item'],
                cabinBag: $raw['baggage']['cabin_bag'],
                checkedBag: $raw['baggage']['checked_bag'],
            ),
            segments: $segments,
        );
    }
}
