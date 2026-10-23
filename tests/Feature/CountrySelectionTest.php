<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CountrySelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.geonames.username' => '', 'services.duffel.access_token' => '']);
    }

    #[Test]
    public function countries_are_served_from_static_data_without_any_provider_credential(): void
    {
        Http::fake();

        $countries = $this->getJson('/konumlar/ulkeler')->assertOk()->json('countries');
        $labels = array_column($countries, 'label', 'code');

        self::assertGreaterThan(240, count($countries));
        self::assertSame('Türkiye', $labels['TR']);
        self::assertSame('İtalya', $labels['IT']);
        self::assertSame('Birleşik Krallık', $labels['GB']);
        foreach ($countries as $country) {
            self::assertSame(['code', 'label'], array_keys($country));
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $country['code']);
        }
        // Turkish alphabetical order: Cezayir < Çad < Çekya < Çin; Irak < İrlanda.
        $order = array_flip(array_column($countries, 'code'));
        self::assertLessThan($order['TD'], $order['DZ']);
        self::assertLessThan($order['CZ'], $order['TD']);
        self::assertLessThan($order['CN'], $order['CZ']);
        self::assertLessThan($order['IE'], $order['IQ']);
        Http::assertNothingSent();
    }

    #[Test]
    public function planner_lets_the_user_pick_countries_even_when_location_search_is_unavailable(): void
    {
        $content = $this->get('/')->assertOk()
            ->assertSee('data-location-countries-url', false)
            ->assertSee('Ülkeyi seçebilirsiniz; ancak şehir ve havalimanı araması şu anda kullanılamıyor.')
            ->assertDontSee('data-flight-location-search-url', false)
            ->assertDontSee('data-location-search-url', false)
            ->getContent();

        // Both places render the country control and no timezone field is shown to the user.
        self::assertSame(2, substr_count($content, 'data-combobox="country"'));
        self::assertDoesNotMatchRegularExpression('/<div class="combobox" data-combobox="country"\s+hidden/', $content);
        self::assertStringNotContainsString('Europe/', $content);
    }

    #[Test]
    public function planner_enables_flight_search_next_to_the_country_selector_when_the_aviation_catalog_is_configured(): void
    {
        config(['services.duffel.access_token' => 'placeholder-not-a-real-token']);

        $content = $this->get('/')->assertOk()
            ->assertSee('data-flight-location-search-url', false)
            ->assertSee('data-location-countries-url', false)
            ->getContent();

        self::assertMatchesRegularExpression('/data-location-catalog-status\s+hidden/', $content);
        self::assertStringNotContainsString('placeholder-not-a-real-token', $content);
    }
}
