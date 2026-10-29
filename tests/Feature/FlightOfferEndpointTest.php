<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\FlightSearch\Domain\AncillaryFee;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use App\Modules\FlightSearch\Domain\FlightSegment;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\FlightSearch\Domain\ProviderSource;
use App\Modules\FlightSearch\Domain\SegmentTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FlightOfferEndpointTest extends TestCase
{
    #[Test]
    public function op_get_offer_returns_the_canonical_flight_offer_without_provider_payload(): void
    {
        $offer = $this->offer();
        $this->app->instance(FlightOfferRepository::class, new class($offer) implements FlightOfferRepository
        {
            public function __construct(private readonly FlightOffer $offer) {}

            public function save(FlightOffer $offer): void {}

            public function find(string $offerId): ?FlightOffer
            {
                return $offerId === $this->offer->providerOfferId ? $this->offer : null;
            }
        });

        $this->getJson('/api/v1/offers/offer-1')
            ->assertOk()
            ->assertJsonPath('provider_offer_id', 'offer-1')
            ->assertJsonPath('price.value.minor_units', 12500)
            ->assertJsonPath('price.value.currency', 'EUR')
            ->assertJsonPath('price.completeness', 'COMPLETE')
            ->assertJsonPath('price.known_lower_bound', null)
            ->assertJsonPath('state', 'CURRENT')
            ->assertJsonPath('baggage.personal_item.value', true)
            ->assertJsonPath('baggage.cabin_baggage.value', false)
            ->assertJsonPath('baggage.checked_baggage.value', false)
            ->assertJsonPath('baggage.fees.0.value.minor_units', 2500)
            ->assertJsonCount(9)
            ->assertJsonPath('journey_type', 'ONE_WAY')
            ->assertJsonPath('segments.0.direction', 'OUTBOUND')
            ->assertJsonPath('segments.0.departure.iana_timezone', fn ($value): bool => is_string($value) && $value !== '')
            ->assertJsonMissingPath('raw_payload');
    }

    #[Test]
    public function op_get_offer_returns_contract_error_with_turkish_message_when_offer_is_missing(): void
    {
        $this->getJson('/api/v1/offers/missing-offer')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('error.message_tr', 'Uçuş teklifi bulunamadı.');
    }

    private function offer(): FlightOffer
    {
        $verifiedAt = new DateTimeImmutable('2026-09-27T10:00:00+00:00');
        // Relative to the real clock: the offer must still be CURRENT whenever the test runs.
        $expiresAt = (new DateTimeImmutable('now'))->modify('+30 days');
        $fee = new AncillaryFee(
            component: 'AIRPORT_CHECK_IN_FEE',
            mandatory: true,
            amount: new Money(2500, 'EUR'),
            certainty: 'EXACT',
            sourceReference: 'provider-a:offer-1',
            fetchedAt: $verifiedAt,
            verifiedAt: $verifiedAt,
        );

        return new FlightOffer(
            providerOfferId: 'offer-1',
            price: new Money(12500, 'EUR'),
            priceCertainty: 'EXACT',
            priceCompleteness: 'COMPLETE',
            baggage: new BaggageAllowance(true, false, false, [$fee], 'provider-a:offer-1', $verifiedAt, $verifiedAt),
            expiresAt: $expiresAt,
            sources: [new ProviderSource(
                providerId: 'provider-a',
                providerOfferId: 'offer-1',
                providerRole: 'UNKNOWN',
                distributionChannel: 'UNKNOWN',
                searchable: true,
                bookable: false,
                searchCapabilityStatus: 'FRESH',
                bookCapabilityStatus: 'UNKNOWN',
                fetchedAt: $verifiedAt,
                verifiedAt: $verifiedAt,
                expiresAt: $expiresAt,
            )],
            segments: [new FlightSegment(
                operatingCarrier: 'TK',
                operatingFlightNumber: 'TK1',
                marketingFlightReferences: ['TK1'],
                carrierModel: 'UNKNOWN',
                departure: new SegmentTime('2026-10-27T12:00:00', 'Europe/Istanbul', new DateTimeImmutable('2026-10-27T09:00:00+00:00')),
                arrival: new SegmentTime('2026-10-27T14:00:00', 'Europe/Berlin', new DateTimeImmutable('2026-10-27T13:00:00+00:00')),
            )],
            bookable: false,
        );
    }
}
