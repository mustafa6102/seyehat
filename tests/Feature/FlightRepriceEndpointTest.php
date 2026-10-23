<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\FlightSearch\Application\FlightRepriceService;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightSegment;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\FlightSearch\Domain\ProviderSource;
use App\Modules\FlightSearch\Domain\SegmentTime;
use App\Modules\ProviderAcl\Application\ProviderCapabilityMatrixService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakeFlightRepriceGateway;
use Tests\Fakes\InMemoryFlightOfferRepository;
use Tests\Fakes\InMemoryProviderCapabilityRepository;
use Tests\TestCase;

final class FlightRepriceEndpointTest extends TestCase
{
    #[Test]
    public function op_reprice_offer_returns_the_v3_3_reprice_result_contract(): void
    {
        $offer = $this->offer();
        $service = new FlightRepriceService(
            new InMemoryFlightOfferRepository([$offer]),
            new ProviderCapabilityMatrixService(new InMemoryProviderCapabilityRepository),
            new FakeFlightRepriceGateway,
        );
        $this->app->instance(FlightRepriceService::class, $service);

        $response = $this->postJson('/api/v1/offers/offer-1/reprice')
            ->assertOk()
            ->assertJsonPath('previous.offer.provider_offer_id', 'offer-1')
            ->assertJsonPath('current.offer.state', 'UNVERIFIED')
            ->assertJsonPath('changes.critical_change', false)
            ->assertJsonPath('previous_approval_valid', false)
            ->assertJsonPath('continuation_allowed', false)
            ->assertJsonPath('reprice_capability.operation', 'reprice')
            ->assertJsonPath('reprice_capability.verification_status', 'UNVERIFIED')
            ->assertJsonStructure([
                'previous',
                'current',
                'changes',
                'reason_codes',
                'previous_approval_valid',
                'continuation_allowed',
                'reprice_capability',
            ]);

        self::assertCount(7, $response->json());
    }

    #[Test]
    public function op_reprice_offer_returns_turkish_contract_error_for_missing_offer(): void
    {
        $this->postJson('/api/v1/offers/missing/reprice')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('error.message_tr', 'Yeniden fiyatlanacak uçuş teklifi bulunamadı.');
    }

    private function offer(): FlightOffer
    {
        $now = new DateTimeImmutable('2026-09-27T10:00:00+00:00');

        return new FlightOffer(
            providerOfferId: 'offer-1',
            price: new Money(10000, 'EUR'),
            priceCertainty: 'EXACT',
            priceCompleteness: 'COMPLETE',
            baggage: new BaggageAllowance(
                true,
                true,
                false,
                sourceReference: 'provider-a:offer-1',
                fetchedAt: $now,
                verifiedAt: $now,
            ),
            expiresAt: new DateTimeImmutable('2099-09-27T11:00:00+00:00'),
            sources: [new ProviderSource('provider-a', 'offer-1', 'UNKNOWN', 'UNKNOWN', true, false, 'FRESH', 'UNKNOWN', $now, $now, null)],
            segments: [new FlightSegment(
                'TK',
                'TK1',
                ['TK1'],
                'UNKNOWN',
                new SegmentTime('2026-10-01T12:00:00', 'Europe/Istanbul', new DateTimeImmutable('2026-10-01T09:00:00+00:00')),
                new SegmentTime('2026-10-01T14:00:00', 'Europe/Berlin', new DateTimeImmutable('2026-10-01T12:00:00+00:00')),
            )],
            bookable: false,
        );
    }
}
