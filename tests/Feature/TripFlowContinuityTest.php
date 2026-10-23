<?php

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\StaticTripRequestService;
use Tests\TestCase;

/** TRIP-001 summary -> flight search continuity (v3.9 journey display, technical ids, legacy records). */
final class TripFlowContinuityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(TripRequestService::class, new StaticTripRequestService);
    }

    #[Test]
    public function round_trip_summary_shows_both_directions_without_collapsing_ranges_or_exposing_catalog_ids(): void
    {
        $content = $this->get('/planlar/trip-1')
            ->assertOk()
            ->assertSee('Aramaya hazır')
            ->assertSee('Gidiş-dönüş')
            ->assertSee('15 Ekim 2026, Perşembe – 20 Ekim 2026, Salı')
            ->assertSee('Esnek tarih aralığı (başlangıç ve bitiş dahil)')
            ->assertSee('27 Ekim 2026, Salı')
            ->assertSee('kayıtlı konum kimliği teknik ayrıntılarda yer alır')
            ->assertDontSee('ROUND_TRIP')
            ->assertDontSee('FLEXIBLE')
            ->getContent();

        // GeoNames is not configured, so names cannot be resolved: the visible title uses Turkish kind labels.
        self::assertMatchesRegularExpression('/<h1 class="page-title">Şehir <span aria-hidden="true">→<\/span><span class="visually-hidden">ile<\/span> Havaalanı<\/h1>/', $content);
        self::assertMatchesRegularExpression('/<details class="technical">.*geonames:745044.*geonames:6299784.*<\/details>/s', $content);
        self::assertSame(1, substr_count($content, 'geonames:745044'));
        self::assertStringContainsString('href="'.route('flights.search', ['tripRequestId' => 'trip-1']).'"', $content);
    }

    #[Test]
    public function legacy_record_keeps_its_backend_status_and_explains_why_search_is_impossible(): void
    {
        $this->get('/planlar/trip-legacy')
            ->assertOk()
            ->assertSee('Aramaya hazır')
            ->assertSee('Bu talep eski tarih biçimiyle kaydedildi.')
            ->assertSee('15 Ekim 2026, Perşembe – 20 Ekim 2026, Salı')
            ->assertSee('Eski tarih biçimi: gidiş/dönüş bilgisi yok')
            ->assertDontSee('Gidiş-dönüş')
            ->assertDontSee('Tek yön');

        $this->get('/planlar/trip-legacy/ucuslar')
            ->assertOk()
            ->assertSee('Bu talebin tarih bilgisiyle uçuş araması yapılamıyor.')
            ->assertSee('Bu talep için uçuş araması başlatılamıyor')
            ->assertDontSee('data-search-start', false);
    }

    #[Test]
    public function every_page_of_the_flow_shows_the_same_step_indicator_with_one_current_step(): void
    {
        foreach (['/' => 'Seyahat talebi', '/planlar/trip-1' => 'Seyahat talebi', '/planlar/trip-1/ucuslar' => 'Uçuş araması', '/teklifler/B-1' => 'Teklif'] as $url => $current) {
            $content = $this->get($url)->assertOk()->getContent();

            self::assertStringContainsString('<nav class="flow-steps" aria-label="Planlama adımları">', $content, $url);
            self::assertSame(1, substr_count($content, 'aria-current="step"'), $url);
            self::assertMatchesRegularExpression('/aria-current="step"\s*>\s*<span class="flow-step-number" aria-hidden="true">\d<\/span>\s*<span>'.$current.'<\/span>/', $content, $url);
        }
    }

    #[Test]
    public function draft_and_unknown_requests_do_not_reach_flight_search(): void
    {
        $this->get('/planlar/trip-draft')->assertOk()->assertSee('Taslak')->assertDontSee('Uçuşları ara');
        $this->get('/planlar/trip-draft/ucuslar')->assertOk()->assertSee('Bu talep henüz uçuş aramasına hazır değil')->assertDontSee('data-search-start', false);
        $this->get('/planlar/yok/ucuslar')->assertNotFound()->assertSee('Aradığınız kayıt bulunamadı');
    }
}
