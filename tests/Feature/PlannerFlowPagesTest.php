<?php

namespace Tests\Feature;

use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Contracts\FlightSearchExecutor;
use App\Modules\FlightSearch\Contracts\FlightSearchOutcome;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class PlannerFlowPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(TripRequestService::class, new FixedTripRequestService);
    }

    #[Test]
    public function traveler_entry_is_not_offered_without_an_age_category_taxonomy_and_trip_entity(): void
    {
        $this->get('/planlar/trip-1')
            ->assertOk()
            ->assertSee('Yolcu bilgileri')
            ->assertSee('Henüz eklenemiyor')
            ->assertDontSee('Yetişkin')
            ->assertDontSee('age_category');
        $this->get('/planlar/trip-1/yolcular')->assertNotFound();
        $this->post('/planlar/trip-1/yolcular', ['age_category' => 'ADULT'])->assertNotFound();
    }

    #[Test]
    public function flight_search_page_is_honest_when_no_provider_is_configured(): void
    {
        $this->get('/planlar/trip-flight/ucuslar')
            ->assertOk()
            ->assertSee('Uçuş araması şu anda kullanılamıyor')
            ->assertSee('Gerçek sağlayıcı verisi olmadan teklif, fiyat veya bagaj bilgisi gösterilmez.')
            ->assertDontSee('data-location-blocker', false)
            ->assertDontSee('data-search-start', false);

        // A blocked request explains the blocker instead of offering a search, with or without a provider.
        foreach ([null, new AvailableSearchExecutor] as $executor) {
            if ($executor !== null) {
                $this->app->instance(FlightSearchExecutor::class, $executor);
            }
            $this->get('/planlar/trip-1/ucuslar')
                ->assertOk()
                ->assertSee('Bu talebin konumlarıyla uçuş araması yapılamıyor.')
                ->assertSee('havacılık kimliği (IATA kodu) yok')
                ->assertSee('Bu talep için uçuş araması başlatılamıyor')
                ->assertSee('Yeni talep oluştur')
                ->assertDontSee('SEARCHABLE')
                ->assertDontSee('data-search-start', false);
        }

        $this->get('/planlar/trip-draft/ucuslar')
            ->assertOk()
            ->assertSee('Bu talep henüz uçuş aramasına hazır değil')
            ->assertDontSee('SEARCHABLE')
            ->assertDontSee('data-location-blocker', false)
            ->assertDontSee('data-date-blocker', false)
            ->assertDontSee('data-search-start', false);
    }

    #[Test]
    public function flight_search_page_uses_the_async_contract_endpoints_when_a_provider_exists(): void
    {
        $this->app->instance(FlightSearchExecutor::class, new AvailableSearchExecutor);

        $this->get('/planlar/trip-flight/ucuslar')
            ->assertOk()
            ->assertSee('data-start-url="'.url('/web-api/v1/flight-search-jobs').'"', false)
            ->assertSee('data-poll-url="'.url('/web-api/v1/flight-search-jobs/__SEARCH__').'"', false)
            ->assertSee('data-storage-key="flight-search:trip-flight"', false)
            ->assertSee('data-trip-request-id="trip-flight"', false)
            ->assertSee('yolculuk tipi, gidiş ve varsa dönüş tarihleri')
            ->assertSee('data-search-start', false)
            ->assertDontSee('data-poll-interval', false);

        config(['flight_search.poll_interval_ms' => 3000]);
        $this->get('/planlar/trip-flight/ucuslar')->assertSee('data-poll-interval="3000"', false);
    }

    #[Test]
    public function flight_search_page_shows_a_compact_non_technical_trip_summary_and_summary_offers_the_search_action(): void
    {
        $this->app->instance(FlightSearchExecutor::class, new AvailableSearchExecutor);

        $page = $this->get('/planlar/trip-flight/ucuslar')->assertOk()
            ->assertSee('Seyahat özeti')
            ->assertSee('IST')
            ->assertSee('ROM')
            ->assertSee('Tek yön')
            ->assertSee('15 Ekim 2026')
            ->assertSee('Yolcu sayısı')
            ->getContent();
        foreach (['duffel:', 'arp_ist_tr', 'cit_rom_it', 'IATA_AIRPORT', 'Europe/', 'SEARCHABLE'] as $internal) {
            self::assertStringNotContainsString($internal, $page);
        }
        self::assertMatchesRegularExpression('/<button type="button" class="btn btn-primary" data-search-start>/', $page);
        self::assertStringContainsString('role="status" aria-live="polite"', $page);

        $this->get('/planlar/trip-flight')->assertOk()->assertSee('Uçuşları ara');
    }

    #[Test]
    public function offer_page_uses_offer_and_reprice_operations_without_a_purchase_action(): void
    {
        $this->get('/teklifler/B-1')
            ->assertOk()
            ->assertSee('data-offer-url="'.url('/api/v1/offers/B-1').'"', false)
            ->assertSee('data-reprice-url="'.url('/api/v1/offers/B-1/reprice').'"', false)
            ->assertSee('Rezervasyon veya partner sitesine yönlendirme adımı henüz kullanıma açılmadı.')
            ->assertDontSee('Satın al');
    }

    #[Test]
    public function expired_form_and_server_error_pages_are_turkish(): void
    {
        self::assertStringContainsString('Sayfanın süresi doldu', view('errors.419')->render());
        self::assertStringContainsString('Beklenmeyen bir hata oluştu', view('errors.500')->render());
    }

    #[Test]
    public function pages_never_expose_the_location_provider_username(): void
    {
        config(['services.geonames.username' => 'secret-geonames-user']);

        foreach (['/', '/planlar/trip-1', '/planlar/trip-1/ucuslar', '/teklifler/B-1'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('secret-geonames-user');
        }
    }
}

final class FixedTripRequestService implements TripRequestService
{
    public function create(array $input, \App\Modules\Authorization\Contracts\PrincipalRef $owner): CreatedTripRequest
    {
        throw new RuntimeException('Not used.');
    }

    public function get(string $id): ?TripRequestView
    {
        $status = ['trip-1' => 'SEARCHABLE', 'trip-draft' => 'DRAFT', 'trip-flight' => 'SEARCHABLE'][$id] ?? null;
        // trip-flight: locations selected from the aviation catalog (v3.8), so no location blocker applies.
        $flight = $id === 'trip-flight';

        return $status === null ? null : new TripRequestView(
            status: $status,
            originPlace: $flight
                ? ['kind' => 'airport', 'value' => 'duffel:arp_ist_tr', 'iana_timezone' => 'Europe/Istanbul', 'aviation_identity' => ['type' => 'IATA_AIRPORT', 'code' => 'IST']]
                : ['kind' => 'city', 'value' => 'İstanbul', 'iana_timezone' => 'Europe/Istanbul'],
            destinationPlace: $flight
                ? ['kind' => 'city', 'value' => 'duffel:cit_rom_it', 'iana_timezone' => 'Europe/Rome', 'aviation_identity' => ['type' => 'IATA_CITY', 'code' => 'ROM']]
                : ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome'],
            dateWindow: null,
            travelerCount: 2,
            preferences: null,
            journeyType: 'ONE_WAY',
            outboundDate: ['type' => 'EXACT', 'date' => '2026-10-15'],
        );
    }

    public function ownedBy(\App\Modules\Authorization\Contracts\PrincipalRef $owner): array
    {
        return [];
    }
}

final class AvailableSearchExecutor implements FlightSearchExecutor
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function execute(FlightSearchCriteria $criteria, callable $publishPartial): FlightSearchOutcome
    {
        return FlightSearchOutcome::completed([]);
    }
}
