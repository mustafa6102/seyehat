<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Frontend design system: centralized tokens, the application shell and the shared Blade components. */
final class DesignSystemTest extends TestCase
{
    #[Test]
    public function component_stylesheet_takes_every_color_from_the_central_tokens(): void
    {
        $tokens = (string) file_get_contents(public_path('assets/design-tokens.css'));
        $css = (string) file_get_contents(public_path('assets/trip-planner.css'));

        foreach (['primary', 'primary-hover', 'primary-subtle', 'secondary', 'accent', 'background', 'surface', 'surface-elevated', 'border', 'text-primary', 'text-secondary', 'text-muted', 'success', 'warning', 'error', 'info', 'focus'] as $token) {
            self::assertMatchesRegularExpression("/--color-{$token}: #[0-9a-f]{6};/", $tokens, $token);
        }

        // No raw color values outside the token file, and every referenced custom property is defined there.
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $css);
        preg_match_all('/var\((--[a-z0-9-]+)/', $css, $used);
        foreach (array_diff(array_unique($used[1]), ['--timeline-time']) as $name) {
            self::assertStringContainsString("{$name}:", $tokens, $name);
        }
    }

    #[Test]
    public function shell_loads_tokens_before_components_and_exposes_an_accessible_mobile_navigation(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();

        self::assertMatchesRegularExpression('#assets/design-tokens\.css\?v=\d+"[\s\S]*assets/trip-planner\.css\?v=\d+"[\s\S]*assets/ui\.js\?v=\d+" defer#', $content);
        self::assertStringContainsString('<a class="skip-link" href="#main">İçeriğe geç</a>', $content);
        // The toggle is hidden until ui.js is ready, so the links stay reachable without scripting.
        self::assertMatchesRegularExpression('/<button type="button" class="icon-btn nav-toggle" aria-label="Menü" aria-controls="primary-nav" aria-expanded="false" data-nav-toggle hidden>/', $content);
        self::assertStringContainsString('<nav class="app-nav" id="primary-nav" aria-label="Ana gezinme">', $content);
        self::assertMatchesRegularExpression('/href="[^"]*"\s+aria-current="page"\s*>Yeni plan</', $content);
    }

    #[Test]
    public function form_components_connect_labels_hints_and_errors_to_their_controls(): void
    {
        $input = Blade::render('<x-ui.input label="E-posta" name="email" hint="İpucu" error="Hata metni" />');

        self::assertStringContainsString('<label class="field-label" for="email">E-posta</label>', $input);
        self::assertMatchesRegularExpression('/<input id="email" type="text" name="email"\s+aria-describedby="email-hint email-error"\s+aria-invalid="true"/', $input);
        self::assertStringContainsString('<span id="email-error" class="field-error">Hata metni</span>', $input);

        $valid = Blade::render('<x-ui.select label="Kabin" name="cabin" :options="[\'e\' => \'Ekonomi\', \'b\' => \'Business\']" selected="b" />');
        self::assertStringNotContainsString('aria-invalid', $valid);
        self::assertMatchesRegularExpression('/<option value="b"\s+selected\s*>Business<\/option>/', $valid);

        self::assertMatchesRegularExpression('/<input type="checkbox" role="switch" name="alerts" value="1"\s+checked/', Blade::render('<x-ui.toggle label="Alarm" name="alerts" :checked="true" />'));
    }

    #[Test]
    public function interactive_components_render_their_state_semantics(): void
    {
        self::assertMatchesRegularExpression('/<button type="button" class="btn btn-primary"\s+aria-busy="true" aria-disabled="true"\s*>Aranıyor<\/button>/', Blade::render('<x-ui.button :loading="true">Aranıyor</x-ui.button>'));
        self::assertStringContainsString('<a href="/x" class="btn btn-secondary">Geri</a>', Blade::render('<x-ui.button variant="secondary" href="/x">Geri</x-ui.button>'));
        self::assertMatchesRegularExpression('/<button type="button" aria-pressed="true" class="chip">/', Blade::render('<x-ui.chip :pressed="true">Aktarmasız</x-ui.chip>'));
        self::assertMatchesRegularExpression('/<button type="button" aria-label="Kapat" class="icon-btn">/', Blade::render('<x-ui.icon-button icon="close" label="Kapat" />'));

        $tabs = Blade::render('<x-ui.tabs label="Tür" :tabs="[\'a\' => \'Uçuşlar\', \'b\' => \'Oteller\']"><x-ui.tab-panel id="a">A</x-ui.tab-panel></x-ui.tabs>');
        self::assertStringContainsString('role="tablist" aria-label="Tür"', $tabs);
        self::assertStringContainsString('id="tab-a" aria-controls="panel-a" aria-selected="true"', $tabs);
        self::assertStringContainsString('role="tabpanel" id="panel-a" aria-labelledby="tab-a"', $tabs);

        $modal = Blade::render('<x-ui.modal id="m" title="Başlık">İçerik</x-ui.modal>');
        self::assertStringContainsString('<dialog class="modal" id="m" aria-labelledby="m-title"', $modal);
        self::assertMatchesRegularExpression('/aria-label="Kapat"[^>]*data-dialog-close/', $modal);

        self::assertStringContainsString('role="alert"', Blade::render('<x-ui.error-state title="Yüklenemedi">Yeniden deneyin.</x-ui.error-state>'));
        self::assertStringContainsString('role="status"', Blade::render('<x-ui.loading-state />'));
    }

    #[Test]
    public function travel_patterns_state_stops_and_unknown_prices_in_words(): void
    {
        $route = Blade::render('<x-travel.route :from="[\'time\' => \'08:35\', \'place\' => \'IST\']" :to="[\'time\' => \'10:15\', \'place\' => \'FCO\']" duration="2 sa 40 dk" :stops="2" />');
        self::assertStringContainsString('2 aktarma', $route);
        self::assertSame(2, substr_count($route, 'class="route-stop"'));
        self::assertStringContainsString('Aktarmasız', Blade::render('<x-travel.route :from="[\'time\' => \'1\', \'place\' => \'A\']" :to="[\'time\' => \'2\', \'place\' => \'B\']" />'));

        // An unverified price is never rendered as an amount.
        $price = Blade::render('<x-travel.price amount="1 TL" state="unknown" />');
        self::assertStringContainsString('Fiyat doğrulanamadı', $price);
        self::assertStringNotContainsString('1 TL', $price);
    }

    #[Test]
    public function style_guide_renders_every_shared_component_in_turkish_outside_production(): void
    {
        $content = (string) $this->get('/tasarim-sistemi')->assertOk()->getContent();

        foreach (['Renk', 'Düğmeler', 'Form', 'Geri bildirim', 'Sekme ve katmanlar', 'Seyahat desenleri'] as $heading) {
            self::assertStringContainsString(">{$heading}</h2>", $content);
        }
        foreach (['class="offer-card', 'class="hotel-card', 'class="day-card', 'class="timeline"', 'data-passengers', 'data-tabs', 'data-dropdown', '<dialog class="drawer"', 'role="tooltip"', 'class="skeleton'] as $marker) {
            self::assertStringContainsString($marker, $content);
        }
        self::assertStringContainsString('sabit örnek veridir; gerçek teklif değildir', $content);
    }
}
