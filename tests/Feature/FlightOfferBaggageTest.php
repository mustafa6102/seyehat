<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\FlightSearch\Application\OfferPriceCalculator;
use App\Modules\FlightSearch\Domain\AncillaryFee;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FlightOfferBaggageTest extends TestCase
{
    #[Test]
    public function ts_flt_005_01_personal_item_is_not_presented_as_cabin_baggage(): void
    {
        $baggage = new BaggageAllowance(
            personalItem: true,
            cabinBag: false,
            checkedBag: false,
            sourceReference: 'provider-a:offer-1',
            fetchedAt: new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
            verifiedAt: new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
        );

        $contract = $baggage->toContract();

        self::assertTrue($contract['personal_item']['value']);
        self::assertFalse($contract['cabin_baggage']['value']);
        self::assertFalse($contract['checked_baggage']['value']);
        self::assertSame(['personal_item', 'cabin_baggage', 'checked_baggage', 'fees'], array_keys($contract));
    }

    #[Test]
    public function ts_flt_005_02_unknown_baggage_fee_remains_explicitly_unknown_and_never_zero(): void
    {
        $fee = new AncillaryFee(
            component: 'BAGGAGE_FEE',
            mandatory: true,
            amount: null,
            certainty: 'UNKNOWN',
            sourceReference: 'provider-a:offer-1',
            fetchedAt: new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
            verifiedAt: null,
        );
        $baggage = new BaggageAllowance(
            true,
            null,
            null,
            [$fee],
            'provider-a:offer-1',
            new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
            null,
        );
        $calculated = (new OfferPriceCalculator)->calculate(
            new Money(10000, 'EUR'),
            'COMPLETE',
            $baggage,
        );

        self::assertNull($calculated->value);
        self::assertSame(10000, $calculated->knownLowerBound?->minorUnits);
        self::assertSame('PARTIAL', $calculated->completeness);
        self::assertSame('UNKNOWN', $calculated->certainty);
        self::assertSame(['BAGGAGE_FEE'], $calculated->unknownComponents);
        self::assertNull($baggage->toContract()['fees'][0]['value']);
        self::assertSame('UNKNOWN', $baggage->toContract()['fees'][0]['certainty']);
    }

    #[Test]
    public function ts_flt_005_03_verified_mandatory_airport_check_in_fee_is_included_in_total(): void
    {
        $fee = new AncillaryFee(
            component: 'AIRPORT_CHECK_IN_FEE',
            mandatory: true,
            amount: new Money(2500, 'EUR'),
            certainty: 'EXACT',
            sourceReference: 'provider-a:offer-1',
            fetchedAt: new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
            verifiedAt: new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
        );
        $baggage = new BaggageAllowance(
            true,
            false,
            false,
            [$fee],
            'provider-a:offer-1',
            new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-27T10:00:00+00:00'),
        );
        $calculated = (new OfferPriceCalculator)->calculate(
            new Money(10000, 'EUR'),
            'COMPLETE',
            $baggage,
        );

        self::assertSame(12500, $calculated->value?->minorUnits);
        self::assertNull($calculated->knownLowerBound);
        self::assertSame('EUR', $calculated->value?->currency);
        self::assertSame('COMPLETE', $calculated->completeness);
        self::assertSame([], $calculated->unknownComponents);
        self::assertSame(2500, $baggage->toContract()['fees'][0]['value']['minor_units']);
        self::assertSame('FRESH', $baggage->toContract()['fees'][0]['verification_status']);
    }

    #[Test]
    public function estimated_mandatory_fee_degrades_derived_price_certainty(): void
    {
        $at = new DateTimeImmutable('2026-09-27T10:00:00+00:00');
        $fee = new AncillaryFee(
            component: 'AIRPORT_CHECK_IN_FEE',
            mandatory: true,
            amount: new Money(2500, 'EUR'),
            certainty: 'ESTIMATED',
            sourceReference: 'provider-a:offer-1',
            fetchedAt: $at,
            verifiedAt: $at,
        );
        $baggage = new BaggageAllowance(true, false, false, [$fee], 'provider-a:offer-1', $at, $at);

        $calculated = (new OfferPriceCalculator)->calculate(new Money(10000, 'EUR'), 'COMPLETE', $baggage);

        self::assertSame('ESTIMATED', $calculated->certainty);
        self::assertSame(12500, $calculated->value?->minorUnits);
    }
}
