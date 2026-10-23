<?php

namespace Tests\Feature;

use App\Modules\Api\Contracts\CreatedTripRequest;
use App\Modules\Api\Contracts\TripRequestService;
use App\Modules\Api\Contracts\TripRequestView;
use App\Modules\Authorization\Contracts\PrincipalRef;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TripPlannerWebTest extends TestCase
{
    private RecordingTripRequestService $tripRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tripRequests = new RecordingTripRequestService;
        $this->app->instance(TripRequestService::class, $this->tripRequests);
    }

    #[Test]
    public function root_renders_the_accessible_turkish_trip_request_form(): void
    {
        $response = $this->get('/')
            ->assertOk()
            ->assertSee('Seyahat Planlayıcısı')
            ->assertSee('Nereden?')
            ->assertSee('Nereye?')
            ->assertSee('Kesin tarih')
            ->assertSee('Esnek tarihler')
            ->assertDontSee('Uçuş sonuçları');

        self::assertMatchesRegularExpression(
            '/aria-describedby="[^"]*origin-help[^"]*"/',
            $response->getContent(),
        );
        foreach (['adults', 'children', 'infants'] as $field) {
            self::assertStringContainsString('name="'.$field.'"', $response->getContent());
        }
    }

    #[Test]
    public function simplified_form_uses_compact_native_controls_and_one_primary_action(): void
    {
        $content = $this->get('/')->assertOk()
            ->assertSee('Yolculuğunuzu planlayın')
            ->assertSee('Gidiş tarihi')
            ->assertSee('Dönüş tarihi')
            // Redundant explanations are gone from the visible form.
            ->assertDontSee('Rotanızı, tarihlerinizi ve yolcu sayısını girin.')
            ->assertDontSee('Talebiniz kaydedildikten sonra uygun uçuşları arayabilirsiniz.')
            ->assertDontSee('Zorunlu alanlar yıldızla işaretlenmiştir.')
            ->assertDontSee('Bu adımda uçuş veya fiyat sonucu üretilmez')
            ->assertDontSee('Uçuş fiyatı araması için yolcu dağılımını girin.')
            ->getContent();

        // One primary action in the page content (the header's logout form is navigation); nothing suggests purchase or booking.
        self::assertSame(1, substr_count(substr($content, (int) strpos($content, '<main')), 'type="submit"'));
        self::assertMatchesRegularExpression('/<button type="submit" class="btn btn-primary btn-cta">Seyahat talebi oluştur<\/button>/', $content);
        self::assertDoesNotMatchRegularExpression('/Satın al|Rezervasyon yap/u', $content);

        // Journey type and date preference stay native radio groups (keyboard arrows, submitted values unchanged).
        self::assertMatchesRegularExpression('/<div class="segmented" role="radiogroup" aria-label="Yolculuk tipi"\s*>/', $content);
        self::assertStringContainsString('<input type="radio" name="journey_type" value="ONE_WAY"', $content);
        self::assertStringContainsString('<input type="radio" name="journey_type" value="ROUND_TRIP"', $content);
        foreach (['outbound' => 'Gidiş', 'return' => 'Dönüş'] as $direction => $label) {
            self::assertStringContainsString("role=\"radiogroup\" aria-label=\"{$label} için tarih tercihi\"", $content);
            self::assertMatchesRegularExpression("/name=\"{$direction}_date_mode\" value=\"exact\" checked/", $content);
            self::assertStringContainsString("name=\"{$direction}_date_mode\" value=\"flexible\"", $content);
            // Exact is the default mode, so only the exact date field is shown.
            self::assertMatchesRegularExpression("/data-date-fields=\"exact\"\\s*>[\\s\\S]*?name=\"{$direction}_exact_date\"/", $content);
            self::assertMatchesRegularExpression("/data-date-fields=\"flexible\"\\s+hidden\\s*>[\\s\\S]*?name=\"{$direction}_start_date\"/", $content);
        }

        // Passengers: labelled number inputs with +/- buttons (enabled by the script) and a visible total.
        foreach (['adults' => 'Yetişkin', 'children' => 'Çocuk', 'infants' => 'Bebek'] as $field => $label) {
            self::assertStringContainsString("<label for=\"passenger-{$field}\">{$label}</label>", $content);
            self::assertStringContainsString("aria-label=\"{$label} sayısını azalt\" aria-controls=\"passenger-{$field}\" hidden>", $content);
            self::assertStringContainsString("aria-label=\"{$label} sayısını artır\" aria-controls=\"passenger-{$field}\" hidden>", $content);
        }
        self::assertMatchesRegularExpression('/Toplam <strong data-passenger-total>1<\/strong> yolcu/', $content);
        self::assertSame(6, substr_count($content, 'type="button" class="stepper-button"'));
        self::assertStringNotContainsString('age_category', $content);
    }

    #[Test]
    public function every_radio_is_rendered_inside_its_own_option_card_next_to_its_text(): void
    {
        $content = $this->get('/')->assertOk()->getContent();

        // Structural guard for the detached-radio bug: each radio is the first child of its own clickable label and is
        // immediately followed by its text, so layout can never separate the circle from what it selects.
        $choices = [
            'journey_type' => ['ONE_WAY' => 'Tek yön', 'ROUND_TRIP' => 'Gidiş-dönüş'],
            'outbound_date_mode' => ['exact' => 'Kesin tarih', 'flexible' => 'Esnek tarihler'],
            'return_date_mode' => ['exact' => 'Kesin tarih', 'flexible' => 'Esnek tarihler'],
        ];
        foreach ($choices as $name => $options) {
            foreach ($options as $value => $text) {
                self::assertMatchesRegularExpression('/<label class="choice"><input type="radio" name="'.$name.'" value="'.$value.'"[^>]*><span>'.preg_quote($text, '/').'<\/span><\/label>/u', $content);
            }
        }
        self::assertSame(6, substr_count($content, 'type="radio"'));
        self::assertSame(6, substr_count($content, '<label class="choice"><input type="radio"'));

        // Assets are versioned so new markup is never paired with a stale cached stylesheet or script.
        self::assertMatchesRegularExpression('#assets/trip-planner\.css\?v=\d+"#', $content);
        self::assertMatchesRegularExpression('#assets/trip-planner\.js\?v=\d+"#', $content);
    }

    #[Test]
    public function stylesheet_never_stretches_radios_and_keeps_them_visible_inside_the_option_card(): void
    {
        $css = (string) file_get_contents(public_path('assets/trip-planner.css'));

        // The base control rule (full width, 44px min-height, padding) must exclude radios and checkboxes.
        self::assertStringContainsString('input:where(:not([type="radio"], [type="checkbox"])), select {', $css);
        self::assertDoesNotMatchRegularExpression('/(^|[\s,}])input\s*(,[^{]*)?\{[^}]*width:\s*100%/', $css);
        // Option cards lay the radio and its text out on one line; the radio is not hidden or taken out of flow.
        self::assertMatchesRegularExpression('/\.choice \{[^}]*display: inline-flex;[^}]*align-items: center;/', $css);
        self::assertDoesNotMatchRegularExpression('/\.(segmented|choice)[^{]*input[^{]*\{[^}]*(position:\s*absolute|opacity:\s*0)/', $css);
        self::assertMatchesRegularExpression('/\.choice:has\(input:checked\) > span \{[^}]*font-weight: 800;/', $css);
        self::assertMatchesRegularExpression('/\.choice:has\(input:focus-visible\) \{[^}]*box-shadow: var\(--focus\);/', $css);
    }

    #[Test]
    public function validation_errors_appear_next_to_their_sections_and_keep_valid_selections(): void
    {
        $this->from('/')->post('/planla', $this->form([
            'outbound_date_mode' => 'flexible',
            'outbound_start_date' => '2026-10-15',
        ]))->assertRedirect('/');

        $content = $this->get('/')->assertOk()->getContent();

        // Journey type: error right below the segmented choice, whose radios are marked invalid.
        self::assertMatchesRegularExpression('/aria-describedby="journey-type-error"[\s\S]*?aria-invalid="true"[\s\S]*?<span id="journey-type-error" class="field-error">Yolculuk tipini seçin: tek yön veya gidiş-dönüş\.<\/span>/u', $content);
        // Flexible outbound stays selected and visible, with the missing end date reported under that field.
        self::assertMatchesRegularExpression('/name="outbound_date_mode" value="flexible" checked/', $content);
        self::assertMatchesRegularExpression('/data-date-fields="exact"\s+hidden\s*>/', $content);
        self::assertStringContainsString('name="outbound_start_date" value="2026-10-15"', $content);
        self::assertMatchesRegularExpression('/name="outbound_end_date"[^>]*aria-invalid="true"[^>]*>\s*<span id="outbound-end-date-error" class="field-error">Esnek gidiş için bitiş tarihini girin\.<\/span>/u', $content);
        // Location selections that were valid are kept as hidden canonical values.
        self::assertStringContainsString('<input type="hidden" name="origin_value" value="İstanbul"', $content);

        // A composition without any passenger is rejected by the server and reported inside the passenger section.
        $this->from('/')->post('/planla', $this->form([
            'journey_type' => 'ONE_WAY',
            'outbound_date_mode' => 'exact',
            'outbound_exact_date' => '2026-10-15',
            'adults' => '0',
            'children' => '0',
            'infants' => '0',
        ]))->assertSessionHasErrors(['adults' => 'En az bir yolcu seçin.']);
        $content = $this->get('/')->assertOk()->getContent();
        self::assertMatchesRegularExpression('/<fieldset class="form-section passengers" data-passengers\s+aria-describedby="passenger-error"/', $content);
        self::assertMatchesRegularExpression('/Toplam <strong data-passenger-total>0<\/strong> yolcu[\s\S]*?<span id="passenger-error" class="field-error">En az bir yolcu seçin\.<\/span>/u', $content);
        self::assertStringContainsString('name="adults" min="0" step="1" value="0"', $content);
        self::assertNull($this->tripRequests->createdInput);
    }

    #[Test]
    public function location_fields_are_searchable_comboboxes_without_a_visible_timezone_or_free_text_value(): void
    {
        $content = $this->get('/')
            ->assertOk()
            ->assertDontSee('IANA')
            ->assertDontSee('saat dilimi')
            ->assertDontSee('Europe/Istanbul')
            ->getContent();

        foreach (['origin', 'destination'] as $prefix) {
            foreach (['country', 'location'] as $step) {
                $id = "{$prefix}-{$step}";
                self::assertMatchesRegularExpression("/<label for=\"{$id}\">/", $content);
                self::assertMatchesRegularExpression(
                    "/id=\"{$id}\"\\s+type=\"text\"\\s+role=\"combobox\"\\s+aria-autocomplete=\"list\"\\s+aria-expanded=\"false\"\\s+aria-controls=\"{$id}-listbox\"/",
                    $content,
                );
                self::assertStringContainsString("id=\"{$id}-listbox\" class=\"combobox-listbox\" role=\"listbox\"", $content);
            }

            // SCH-LocationRef fields are only hidden, catalog-filled values; never user-typed inputs.
            foreach (['kind', 'value', 'timezone'] as $field) {
                self::assertMatchesRegularExpression("/<input type=\"hidden\" name=\"{$prefix}_{$field}\"/", $content);
                self::assertDoesNotMatchRegularExpression("/<(select|input)(?![^>]*type=\"hidden\")[^>]*name=\"{$prefix}_{$field}\"/", $content);
            }
        }

        self::assertStringContainsString(
            'data-location-kinds=\'{"city":"\u015eehir","airport":"Havaalan\u0131","station":"\u0130stasyon","supported_location":"Di\u011fer konum"}\'',
            $content,
        );
    }

    #[Test]
    public function location_search_stays_disabled_and_explained_while_no_approved_catalog_exists(): void
    {
        $content = $this->get('/')
            ->assertOk()
            ->assertSee('Ülkeyi seçebilirsiniz; ancak şehir ve havalimanı araması şu anda kullanılamıyor.')
            ->assertSee('data-location-countries-url', false)
            ->getContent();

        self::assertSame(4, preg_match_all('/role="combobox"[^>]*aria-describedby="[^"]*location-catalog-status[^"]*"[^>]*disabled/s', $content));
    }

    #[Test]
    public function missing_location_selection_returns_one_accessible_turkish_error_per_place(): void
    {
        $this->from('/')
            ->post('/planla', [
                'destination_kind' => 'city',
                'destination_value' => 'Roma',
                'destination_timezone' => 'Europe/Rome',
                'date_mode' => 'exact',
                'exact_date' => '2026-10-15',
                'traveler_count' => '1',
            ])
            ->assertRedirect('/')
            ->assertSessionHasErrors(['origin_kind', 'origin_value', 'origin_timezone']);

        $content = $this->get('/')->getContent();

        self::assertSame(1, substr_count($content, '<li>Başlangıç konumunu listeden seçin.</li>'));
        self::assertStringContainsString('<span id="origin-location-error" class="field-error">Başlangıç konumunu listeden seçin.</span>', $content);
        self::assertMatchesRegularExpression('/id="origin-location"[^>]*aria-describedby="[^"]*origin-location-error[^"]*"\s+aria-invalid="true"/s', $content);
        self::assertStringNotContainsString('destination-location-error', $content);
        self::assertStringContainsString('<input type="hidden" name="destination_value" value="Roma"', $content);
        self::assertNull($this->tripRequests->createdInput);
    }

    #[Test]
    public function tampered_location_timezone_is_rejected_without_exposing_timezone_terms(): void
    {
        $this->from('/')
            ->post('/planla', [
                'origin_kind' => 'city',
                'origin_value' => 'İstanbul',
                'origin_timezone' => 'Mars/Olympus',
                'destination_kind' => 'region',
                'destination_value' => 'Roma',
                'destination_timezone' => 'Europe/Rome',
                'date_mode' => 'exact',
                'exact_date' => '2026-10-15',
                'traveler_count' => '1',
            ])
            ->assertRedirect('/')
            ->assertSessionHasErrors(['origin_timezone', 'destination_kind']);

        $this->get('/')
            ->assertSee('Seçilen başlangıç konumu doğrulanamadı; listeden yeniden seçin.')
            ->assertSee('Seçilen varış konumu desteklenmiyor; listeden yeniden seçin.')
            ->assertDontSee('IANA');

        self::assertNull($this->tripRequests->createdInput);
    }

    #[Test]
    public function one_way_exact_submission_sends_the_canonical_journey_and_shows_backend_state(): void
    {
        $this->post('/planla', $this->form([
            'journey_type' => 'ONE_WAY',
            'outbound_date_mode' => 'exact',
            'outbound_exact_date' => '2026-10-15',
            'traveler_count' => '2',
            'pace_preference' => 'dengeli',
        ]))->assertRedirect('/planlar/trip-ui-1');

        $this->get('/planlar/trip-ui-1')
            ->assertOk()
            ->assertSee('İstanbul')
            ->assertSee('NRT')
            ->assertSee('Aramaya hazır')
            ->assertSee('Tek yön')
            ->assertSee('Gidiş')
            ->assertSee('15 Ekim 2026')
            ->assertSee('Kesin tarih')
            ->assertDontSee('Dönüş')
            ->assertDontSee('ONE_WAY')
            ->assertSee('dengeli')
            ->assertSee('trip-ui-1')
            ->assertSee('Yolcu bilgileri şu anda bu arayüzden eklenemiyor')
            ->assertSee('Uçuş sağlayıcısı henüz yapılandırılmadığı için arama şu anda başlatılamıyor.');

        self::assertSame([
            'status' => 'DRAFT',
            'origin_place' => [
                'kind' => 'city',
                'value' => 'İstanbul',
                'iana_timezone' => 'Europe/Istanbul',
            ],
            'destination_place' => [
                'kind' => 'airport',
                'value' => 'NRT',
                'iana_timezone' => 'Asia/Tokyo',
            ],
            'journey_type' => 'ONE_WAY',
            'outbound_date' => ['type' => 'EXACT', 'date' => '2026-10-15'],
            'traveler_count' => 2,
            'passenger_composition' => null,
            'preferences' => ['pace_preference' => 'dengeli'],
        ], $this->tripRequests->createdInput);
    }

    #[Test]
    public function one_way_flexible_and_every_round_trip_combination_reach_flight_criteria_unchanged(): void
    {
        $exact = fn (string $direction, string $date): array => ["{$direction}_date_mode" => 'exact', "{$direction}_exact_date" => $date];
        $flexible = fn (string $direction, string $start, string $end): array => ["{$direction}_date_mode" => 'flexible', "{$direction}_start_date" => $start, "{$direction}_end_date" => $end];
        $cases = [
            'ONE_WAY FLEXIBLE' => [['journey_type' => 'ONE_WAY'] + $flexible('outbound', '2026-10-15', '2026-10-20'), ['type' => 'FLEXIBLE', 'start_date' => '2026-10-15', 'end_date' => '2026-10-20'], null],
            'EXACT -> EXACT' => [['journey_type' => 'ROUND_TRIP'] + $exact('outbound', '2026-10-15') + $exact('return', '2026-10-22'), ['type' => 'EXACT', 'date' => '2026-10-15'], ['type' => 'EXACT', 'date' => '2026-10-22']],
            'EXACT -> FLEXIBLE' => [['journey_type' => 'ROUND_TRIP'] + $exact('outbound', '2026-10-15') + $flexible('return', '2026-10-20', '2026-10-25'), ['type' => 'EXACT', 'date' => '2026-10-15'], ['type' => 'FLEXIBLE', 'start_date' => '2026-10-20', 'end_date' => '2026-10-25']],
            'FLEXIBLE -> EXACT' => [['journey_type' => 'ROUND_TRIP'] + $flexible('outbound', '2026-10-15', '2026-10-20') + $exact('return', '2026-10-15'), ['type' => 'FLEXIBLE', 'start_date' => '2026-10-15', 'end_date' => '2026-10-20'], ['type' => 'EXACT', 'date' => '2026-10-15']],
            'FLEXIBLE -> FLEXIBLE' => [['journey_type' => 'ROUND_TRIP'] + $flexible('outbound', '2026-10-15', '2026-10-20') + $flexible('return', '2026-10-25', '2026-10-30'), ['type' => 'FLEXIBLE', 'start_date' => '2026-10-15', 'end_date' => '2026-10-20'], ['type' => 'FLEXIBLE', 'start_date' => '2026-10-25', 'end_date' => '2026-10-30']],
        ];

        foreach ($cases as $name => [$fields, $outbound, $return]) {
            $this->post('/planla', $this->form($fields))->assertRedirect('/planlar/trip-ui-1');

            self::assertSame($outbound, $this->tripRequests->createdInput['outbound_date'], $name);
            self::assertSame($return, $this->tripRequests->createdInput['return_date'] ?? null, $name);
            // The planner never writes the legacy date_window.
            self::assertArrayNotHasKey('date_window', $this->tripRequests->createdInput);

            $criteria = FlightSearchCriteria::fromSearchableTripRequest('trip-ui-1', $this->tripRequests->stored);
            self::assertSame([$fields['journey_type'], $outbound, $return], [$criteria->journeyType, $criteria->outboundDate, $criteria->returnDate], $name);
        }

        $this->get('/planlar/trip-ui-1')
            ->assertOk()
            ->assertSee('Gidiş-dönüş')
            ->assertSee('Dönüş')
            ->assertSee('Esnek tarih aralığı (başlangıç ve bitiş dahil)')
            ->assertSee('Belirtilmedi');
    }

    #[Test]
    public function journey_and_date_validation_returns_turkish_errors_and_stores_nothing(): void
    {
        $cases = [
            'journey type missing' => [[], 'journey_type', 'Yolculuk tipini seçin: tek yön veya gidiş-dönüş.'],
            'invalid journey type' => [['journey_type' => 'MULTI_CITY'], 'journey_type', 'Geçerli bir yolculuk tipi seçin.'],
            'missing outbound' => [['journey_type' => 'ONE_WAY', 'outbound_date_mode' => 'exact'], 'outbound_exact_date', 'Gidiş tarihini girin.'],
            'impossible outbound date' => [['journey_type' => 'ONE_WAY', 'outbound_date_mode' => 'exact', 'outbound_exact_date' => '2026-02-30'], 'outbound_exact_date', 'Gidiş tarihini geçerli biçimde girin.'],
            'reversed outbound range' => [['journey_type' => 'ONE_WAY', 'outbound_date_mode' => 'flexible', 'outbound_start_date' => '2026-10-20', 'outbound_end_date' => '2026-10-15'], 'outbound_end_date', 'Gidiş için bitiş tarihi başlangıç tarihinden önce olamaz.'],
            'ONE_WAY with return' => [['journey_type' => 'ONE_WAY', 'outbound_date_mode' => 'exact', 'outbound_exact_date' => '2026-10-15', 'return_date_mode' => 'exact', 'return_exact_date' => '2026-10-20'], 'return_exact_date', 'Tek yön yolculukta dönüş tarihi gönderilemez.'],
            'ROUND_TRIP without return' => [['journey_type' => 'ROUND_TRIP', 'outbound_date_mode' => 'exact', 'outbound_exact_date' => '2026-10-15'], 'return_date_mode', 'Gidiş-dönüş yolculukta dönüş tarihi zorunludur.'],
            'reversed return range' => [['journey_type' => 'ROUND_TRIP', 'outbound_date_mode' => 'exact', 'outbound_exact_date' => '2026-10-15', 'return_date_mode' => 'flexible', 'return_start_date' => '2026-10-25', 'return_end_date' => '2026-10-20'], 'return_end_date', 'Dönüş için bitiş tarihi başlangıç tarihinden önce olamaz.'],
            'return before outbound' => [['journey_type' => 'ROUND_TRIP', 'outbound_date_mode' => 'flexible', 'outbound_start_date' => '2026-10-15', 'outbound_end_date' => '2026-10-20', 'return_date_mode' => 'exact', 'return_exact_date' => '2026-10-14'], 'return_date', 'Dönüş tarihi, gidiş tarihinden önce olamaz.'],
        ];

        foreach ($cases as $name => [$fields, $errorField, $message]) {
            $this->from('/')->post('/planla', $this->form($fields))
                ->assertRedirect('/')
                ->assertSessionHasErrors([$errorField => $message]);
        }

        $this->get('/')->assertSee('Dönüş tarihi, gidiş tarihinden önce olamaz.');
        self::assertNull($this->tripRequests->createdInput);
    }

    #[Test]
    public function browser_shows_return_controls_only_for_round_trip_and_never_fabricates_values(): void
    {
        $content = $this->get('/')->assertOk()->assertSee('Tek yön')->assertSee('Gidiş-dönüş')->getContent();

        // No journey type is pre-selected; the return section is hidden and disabled so nothing is submitted.
        self::assertDoesNotMatchRegularExpression('/name="journey_type" value="[A-Z_]+" checked/', $content);
        self::assertMatchesRegularExpression('/<div data-return-date\s+hidden\s*>/', $content);
        self::assertMatchesRegularExpression('/<fieldset class="travel-date" data-travel-date="return"\s+disabled\s*>/', $content);
        self::assertMatchesRegularExpression('/<fieldset class="travel-date" data-travel-date="outbound"\s*>/', $content);
        foreach (['outbound', 'return'] as $direction) {
            foreach (['exact_date', 'start_date', 'end_date'] as $field) {
                self::assertMatchesRegularExpression("/name=\"{$direction}_{$field}\" value=\"\"/", $content);
            }
        }
        self::assertStringContainsString('Başlangıç ve bitiş günleri de uygun tarihlere dahildir.', $content);

        // After a validation error for a round trip the return section is rendered enabled with the old input.
        $this->from('/')->post('/planla', $this->form(['journey_type' => 'ROUND_TRIP', 'outbound_date_mode' => 'exact', 'outbound_exact_date' => '2026-10-15']));
        $roundTrip = $this->get('/')->getContent();
        self::assertMatchesRegularExpression('/<div data-return-date\s*>/', $roundTrip);
        self::assertMatchesRegularExpression('/name="journey_type" value="ROUND_TRIP" checked/', $roundTrip);

        // The script only toggles visibility/disabled state; it never assigns a journey type or date value.
        $script = (string) file_get_contents(public_path('assets/trip-planner.js'));
        self::assertStringContainsString('returnFields.disabled = !roundTrip;', $script);
        self::assertDoesNotMatchRegularExpression('/(journey_type|_date)[^\n]*\.(value|checked)\s*=[^=]/', $script);
    }

    #[Test]
    public function legacy_trip_request_is_shown_as_recorded_and_cannot_start_a_flight_search(): void
    {
        $this->tripRequests->stored = new TripRequestView(
            status: 'SEARCHABLE',
            originPlace: ['kind' => 'city', 'value' => 'İstanbul', 'iana_timezone' => 'Europe/Istanbul'],
            destinationPlace: ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome'],
            dateWindow: ['start_date' => '2026-10-15', 'end_date' => '2026-10-20'],
            travelerCount: 1,
            preferences: null,
        );

        $this->get('/planlar/trip-ui-1')
            ->assertOk()
            ->assertSee('Eski tarih biçimi: gidiş/dönüş bilgisi yok')
            ->assertDontSee('Tek yön')
            ->assertDontSee('Gidiş-dönüş');
        $this->get('/planlar/trip-ui-1/ucuslar')
            ->assertOk()
            ->assertSee('Bu talebin tarih bilgisiyle uçuş araması yapılamıyor.')
            ->assertSee('tarihin gidiş tarihi mi yoksa seyahat süresi mi olduğu bilinmediğinden uçuş araması yapılamaz')
            ->assertDontSee('data-search-start', false);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function form(array $fields): array
    {
        return $fields + [
            'origin_kind' => 'city',
            'origin_value' => 'İstanbul',
            'origin_timezone' => 'Europe/Istanbul',
            'destination_kind' => 'airport',
            'destination_value' => 'NRT',
            'destination_timezone' => 'Asia/Tokyo',
            'traveler_count' => '1',
        ];
    }

    #[Test]
    public function draft_summary_offers_no_search_and_unknown_trip_is_turkish_404(): void
    {
        $this->tripRequests->stored = new TripRequestView(
            status: 'DRAFT',
            originPlace: ['kind' => 'city', 'value' => 'İstanbul', 'iana_timezone' => 'Europe/Istanbul'],
            destinationPlace: ['kind' => 'city', 'value' => 'Roma', 'iana_timezone' => 'Europe/Rome'],
            dateWindow: null,
            travelerCount: 1,
            preferences: null,
        );

        $this->get('/planlar/trip-ui-1')
            ->assertOk()
            ->assertSee('Taslak')
            ->assertSee('Bu talep henüz aramaya hazır değil.')
            ->assertDontSee('Uçuşları ara');

        $this->get('/planlar/bilinmeyen')
            ->assertNotFound()
            ->assertSee('Aradığınız kayıt bulunamadı');
    }
}

final class RecordingTripRequestService implements TripRequestService
{
    /** @var array<string, mixed>|null */
    public ?array $createdInput = null;

    public ?TripRequestView $stored = null;

    public function create(array $input, PrincipalRef $owner): CreatedTripRequest
    {
        $this->createdInput = $input;
        $this->stored = new TripRequestView(
            status: 'SEARCHABLE',
            originPlace: $input['origin_place'],
            destinationPlace: $input['destination_place'],
            dateWindow: null,
            travelerCount: $input['traveler_count'],
            preferences: $input['preferences'],
            journeyType: $input['journey_type'],
            outboundDate: $input['outbound_date'],
            returnDate: $input['return_date'] ?? null,
        );

        return new CreatedTripRequest('trip-ui-1', $this->stored);
    }

    public function get(string $id): ?TripRequestView
    {
        return $id === 'trip-ui-1' ? $this->stored : null;
    }
}
