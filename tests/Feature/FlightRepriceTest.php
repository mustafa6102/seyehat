<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\FlightSearch\Application\FlightRepriceService;
use App\Modules\FlightSearch\Application\RepriceContinuationPolicy;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\FareConditionSnapshot;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightSegment;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\FlightSearch\Domain\ProviderSource;
use App\Modules\FlightSearch\Domain\RepriceReason;
use App\Modules\FlightSearch\Domain\SegmentTime;
use App\Modules\ProviderAcl\Application\ProviderCapabilityMatrixService;
use App\Modules\ProviderAcl\Domain\ProviderCapability;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakeFlightRepriceGateway;
use Tests\Fakes\InMemoryFlightOfferRepository;
use Tests\Fakes\InMemoryProviderCapabilityRepository;
use Tests\TestCase;

final class FlightRepriceTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new DateTimeImmutable('2026-09-27T10:00:00+00:00');
    }

    #[Test]
    public function ts_flt_reprice_001_01_model_a_success_preserves_verified_price_and_timestamp(): void
    {
        $result = $this->service($this->offer(), supported: true)->reprice('offer-1', $this->now);

        self::assertSame('CURRENT', $result->snapshot->offer->state($this->now));
        self::assertSame(10000, $result->snapshot->offer->price->minorUnits);
        self::assertSame('2026-09-27T10:00:00+00:00', $result->snapshot->toContract()['captured_at']);
        self::assertTrue((new RepriceContinuationPolicy)->canContinue('MODEL_A', $result));
    }

    #[Test]
    public function ts_flt_reprice_001_02_model_a_unsupported_reprice_keeps_explicit_warning_semantics(): void
    {
        $result = $this->service($this->offer(), supported: false)->reprice('offer-1', $this->now);

        self::assertSame('UNVERIFIED', $result->snapshot->offer->state($this->now));
        self::assertSame('UNKNOWN', $result->capabilityStatus);
        self::assertSame([RepriceReason::REPRICE_UNVERIFIED], $result->reasonCodes);
        self::assertSame('Sağlayıcı yeniden fiyatlamayı desteklemiyor; fiyat değişebilir.', $result->messageTr);
        self::assertTrue((new RepriceContinuationPolicy)->canContinue('MODEL_A', $result));
    }

    #[Test]
    public function ts_flt_reprice_001_03_model_b_price_change_requires_new_approval(): void
    {
        $previous = $this->offer();
        $result = $this->service($previous, true, $this->offer(priceMinorUnits: 12500))->reprice('offer-1', $this->now);
        $policy = new RepriceContinuationPolicy;

        self::assertSame('CHANGED', $result->snapshot->offer->state($this->now));
        self::assertFalse($result->previousApprovalValid);
        self::assertFalse($policy->canContinue('MODEL_B', $result));
        self::assertTrue($policy->canContinue('MODEL_B', $result, newApprovalGiven: true));
        self::assertSame([RepriceReason::OFFER_CHANGED], $result->reasonCodes);
        self::assertTrue($result->changes->priceChanged);
        self::assertTrue($result->changes->criticalChange);
    }

    #[Test]
    public function ts_flt_reprice_001_04_expired_offer_cannot_continue_when_reprice_fails(): void
    {
        $expired = $this->offer(expiresAt: '2026-09-27T09:59:59+00:00');
        $result = $this->service($expired, true, timesOut: true)->reprice('offer-1', $this->now);

        self::assertSame('EXPIRED', $result->snapshot->offer->state($this->now));
        self::assertFalse($result->snapshot->offer->bookable);
        self::assertContains(RepriceReason::OFFER_EXPIRED, $result->reasonCodes);
        self::assertFalse((new RepriceContinuationPolicy)->canContinue('MODEL_C', $result));
    }

    #[Test]
    public function ts_flt_reprice_001_05_provider_timeout_marks_offer_unverified(): void
    {
        $result = $this->service($this->offer(), true, timesOut: true)->reprice('offer-1', $this->now);

        self::assertSame('UNVERIFIED', $result->snapshot->offer->state($this->now));
        self::assertContains(RepriceReason::PROVIDER_TIMEOUT, $result->reasonCodes);
        self::assertContains(RepriceReason::REPRICE_UNVERIFIED, $result->reasonCodes);
        self::assertFalse($result->previousApprovalValid);
        self::assertSame('Sağlayıcı yanıtı zamanında alınamadı; teklif doğrulanamadı.', $result->messageTr);
    }

    #[Test]
    public function ts_flt_reprice_001_06_unchanged_offer_remains_valid_for_model_b_or_c(): void
    {
        $result = $this->service($this->offer(), true, $this->offer())->reprice('offer-1', $this->now);

        self::assertSame('CURRENT', $result->snapshot->offer->state($this->now));
        self::assertTrue($result->previousApprovalValid);
        self::assertTrue($result->continuationAllowed);
        self::assertTrue((new RepriceContinuationPolicy)->canContinue('MODEL_C', $result));
        self::assertSame([], $result->reasonCodes);
    }

    #[Test]
    public function ts_flt_reprice_001_07_baggage_condition_change_invalidates_previous_approval(): void
    {
        $changed = $this->offer(baggage: new BaggageAllowance(true, false, true));
        $result = $this->service($this->offer(), true, $changed)->reprice('offer-1', $this->now);

        self::assertSame('CHANGED', $result->snapshot->offer->state($this->now));
        self::assertFalse($result->previousApprovalValid);
        self::assertTrue($result->changes->baggageChanged);
        self::assertFalse($result->changes->fareConditionsChanged);
        self::assertFalse((new RepriceContinuationPolicy)->canContinue('MODEL_B', $result));
    }

    #[Test]
    public function fare_condition_change_invalidates_previous_approval(): void
    {
        $term = fn (bool $refundable): array => [
            'key' => 'refundable',
            'value' => $refundable,
            'certainty' => 'EXACT',
            'source_reference' => 'provider-a:offer-1',
            'fetched_at' => '2026-09-27T10:00:00+00:00',
            'verified_at' => '2026-09-27T10:00:00+00:00',
            'expires_at' => '2026-09-27T11:00:00+00:00',
            'verification_status' => 'FRESH',
        ];
        $previous = $this->offer(fareConditions: new FareConditionSnapshot([$term(false)]));
        $current = $this->offer(fareConditions: new FareConditionSnapshot([$term(true)]));

        $result = $this->service($previous, true, $current)->reprice('offer-1', $this->now);

        self::assertTrue($result->changes->fareConditionsChanged);
        self::assertFalse($result->changes->baggageChanged);
        self::assertFalse($result->previousApprovalValid);
        self::assertFalse($result->continuationAllowed);
    }

    #[Test]
    public function unknown_fare_condition_cannot_carry_a_fabricated_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FareConditionSnapshot([[
            'key' => 'refundable',
            'value' => false,
            'certainty' => 'UNKNOWN',
            'source_reference' => 'provider-a:offer-1',
            'fetched_at' => '2026-09-27T10:00:00+00:00',
            'verified_at' => null,
            'expires_at' => null,
            'verification_status' => 'UNVERIFIED',
        ]]);
    }

    #[Test]
    public function fresh_provider_timestamps_alone_are_not_a_price_fare_or_baggage_change(): void
    {
        $term = fn (DateTimeImmutable $readAt): array => [
            'key' => 'refundable',
            'value' => false,
            'certainty' => 'EXACT',
            'source_reference' => 'provider-a:offer-1',
            'fetched_at' => $readAt->format(DATE_ATOM),
            'verified_at' => $readAt->format(DATE_ATOM),
            'expires_at' => '2026-09-27T11:00:00+00:00',
            'verification_status' => 'FRESH',
        ];
        $searchedAt = new DateTimeImmutable('2026-09-27T09:20:00+00:00');
        $previous = $this->offer(fareConditions: new FareConditionSnapshot([$term($searchedAt)]), readAt: $searchedAt);
        // Same price, terms and baggage, re-read by the provider forty minutes later.
        $current = $this->offer(fareConditions: new FareConditionSnapshot([$term($this->now)]), readAt: $this->now);

        $result = $this->service($previous, true, $current)->reprice('offer-1', $this->now);

        self::assertSame('CURRENT', $result->snapshot->offer->state($this->now));
        self::assertSame(['price_changed' => false, 'fare_conditions_changed' => false, 'baggage_changed' => false, 'critical_change' => false], $result->changes->toContract());
        self::assertTrue($result->previousApprovalValid);
        self::assertSame([], $result->reasonCodes);
        // The verification time shown to the user is the provider's new read.
        self::assertSame('2026-09-27T10:00:00+00:00', $result->toContract()['current']['offer']['sources'][0]['verified_at']);
    }

    #[Test]
    public function a_provider_failure_other_than_timeout_marks_the_offer_unverified_without_a_timeout_code(): void
    {
        $result = $this->service($this->offer(), true, fails: true)->reprice('offer-1', $this->now);

        self::assertSame('UNVERIFIED', $result->snapshot->offer->state($this->now));
        self::assertSame([RepriceReason::REPRICE_UNVERIFIED], $result->reasonCodes);
        self::assertFalse($result->previousApprovalValid);
        self::assertFalse($result->continuationAllowed);
        self::assertFalse($result->snapshot->offer->bookable);
        self::assertSame('Teklif sağlayıcıdan doğrulanamadı; güncel olarak gösterilmez.', $result->messageTr);
    }

    /** TS-FLT-REPRICE-001-14 (AC-14): a round trip is verified as one offer with both directions intact. */
    #[Test]
    public function ts_flt_reprice_001_14_a_round_trip_is_verified_whole_under_one_offer_id(): void
    {
        $roundTrip = $this->offer(directions: ['OUTBOUND', 'INBOUND']);

        $result = $this->service($roundTrip, true, repriced: $this->offer(directions: ['OUTBOUND', 'INBOUND']))->reprice('offer-1', $this->now);

        self::assertSame('CURRENT', $result->snapshot->offer->state($this->now));
        self::assertSame('ROUND_TRIP', $result->toContract()['current']['offer']['journey_type']);
        self::assertSame(['OUTBOUND', 'INBOUND'], array_column($result->toContract()['current']['offer']['segments'], 'direction'));
        self::assertTrue($result->previousApprovalValid);
    }

    /**
     * TS-FLT-REPRICE-001-16 (AC-16, OD-022 D3): the provider returning another itinerary under the same offer id - a
     * dropped return, a changed flight or a lost direction - is UNVERIFIED; the stored offer keeps its itinerary.
     */
    #[Test]
    public function ts_flt_reprice_001_16_a_changed_route_or_direction_is_unverified_and_keeps_the_stored_offer(): void
    {
        $roundTrip = $this->offer(directions: ['OUTBOUND', 'INBOUND']);
        foreach ([
            'return dropped' => $this->offer(directions: ['OUTBOUND']),
            'flight changed' => $this->offer(directions: ['OUTBOUND', 'INBOUND'], firstFlight: 'TK9'),
            'direction lost' => $this->offer(directions: ['OUTBOUND', 'OUTBOUND']),
        ] as $case => $repriced) {
            $offers = new InMemoryFlightOfferRepository([$roundTrip]);
            $result = $this->service($roundTrip, true, repriced: $repriced, offers: $offers)->reprice('offer-1', $this->now);

            self::assertSame('UNVERIFIED', $result->snapshot->offer->state($this->now), $case);
            self::assertSame([RepriceReason::REPRICE_UNVERIFIED], $result->reasonCodes, $case);
            self::assertFalse($result->continuationAllowed, $case);
            self::assertFalse($result->toContract()['changes']['critical_change'], $case);
            self::assertSame('Sağlayıcı bu teklif için farklı bir uçuş güzergâhı döndürdü; teklif doğrulanamadı ve seçiminiz değiştirilmedi.', $result->messageTr, $case);
            self::assertSame($roundTrip->itinerarySignature(), $offers->find('offer-1')->itinerarySignature(), $case);
        }
    }

    #[Test]
    public function a_one_way_route_change_under_the_same_offer_id_is_unverified_too(): void
    {
        $result = $this->service($this->offer(), true, repriced: $this->offer(firstFlight: 'TK2'))->reprice('offer-1', $this->now);

        self::assertSame('UNVERIFIED', $result->snapshot->offer->state($this->now));
        self::assertSame([RepriceReason::REPRICE_UNVERIFIED], $result->reasonCodes);
    }

    private function service(
        FlightOffer $previous,
        bool $supported,
        ?FlightOffer $repriced = null,
        bool $timesOut = false,
        bool $fails = false,
        ?InMemoryFlightOfferRepository $offers = null,
    ): FlightRepriceService {
        $capabilities = new InMemoryProviderCapabilityRepository;
        if ($supported) {
            $capabilities->save(new ProviderCapability(
                providerId: 'provider-a',
                operation: 'reprice',
                environment: 'production',
                supported: true,
                status: 'FRESH',
                evidence: 'reprice-evidence',
                verifiedAt: $this->now,
            ));
        }

        return new FlightRepriceService(
            $offers ?? new InMemoryFlightOfferRepository([$previous]),
            new ProviderCapabilityMatrixService($capabilities),
            new FakeFlightRepriceGateway($repriced, $timesOut, $fails),
        );
    }

    private function offer(
        int $priceMinorUnits = 10000,
        ?BaggageAllowance $baggage = null,
        string $expiresAt = '2026-09-27T11:00:00+00:00',
        ?FareConditionSnapshot $fareConditions = null,
        ?DateTimeImmutable $readAt = null,
        array $directions = ['OUTBOUND'],
        string $firstFlight = 'TK1',
    ): FlightOffer {
        $readAt ??= $this->now;
        $departure = new SegmentTime('2026-10-01T12:00:00', 'Europe/Istanbul', new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
        $arrival = new SegmentTime('2026-10-01T14:00:00', 'Europe/Berlin', new DateTimeImmutable('2026-10-01T12:00:00+00:00'));
        $returnDeparture = new SegmentTime('2026-10-08T15:00:00', 'Europe/Berlin', new DateTimeImmutable('2026-10-08T13:00:00+00:00'));
        $returnArrival = new SegmentTime('2026-10-08T19:00:00', 'Europe/Istanbul', new DateTimeImmutable('2026-10-08T16:00:00+00:00'));
        $segments = [];
        foreach ($directions as $i => $direction) {
            $segments[] = $i === 0
                ? new FlightSegment('TK', $firstFlight, [$firstFlight], 'UNKNOWN', $departure, $arrival, $direction)
                : new FlightSegment('TK', 'TK'.(1 + $i), [], 'UNKNOWN', $returnDeparture, $returnArrival, $direction);
        }

        return new FlightOffer(
            providerOfferId: 'offer-1',
            price: new Money($priceMinorUnits, 'EUR'),
            priceCertainty: 'EXACT',
            priceCompleteness: 'COMPLETE',
            baggage: ($baggage ?? new BaggageAllowance(true, true, false))->withSource(
                'provider-a:offer-1',
                $readAt,
                $readAt,
                new DateTimeImmutable($expiresAt),
            ),
            expiresAt: new DateTimeImmutable($expiresAt),
            sources: [new ProviderSource(
                providerId: 'provider-a',
                providerOfferId: 'offer-1',
                providerRole: 'UNKNOWN',
                distributionChannel: 'UNKNOWN',
                searchable: true,
                bookable: true,
                searchCapabilityStatus: 'FRESH',
                bookCapabilityStatus: 'FRESH',
                fetchedAt: $readAt,
                verifiedAt: $readAt,
                expiresAt: new DateTimeImmutable($expiresAt),
            )],
            segments: $segments,
            bookable: true,
            fareConditions: $fareConditions,
        );
    }
}
