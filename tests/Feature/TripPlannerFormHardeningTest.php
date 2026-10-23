<?php

namespace Tests\Feature;

use App\Modules\Api\Contracts\TripRequestService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** TRIP-001 planner form (web) hardening: malformed input, Turkish-only errors, old input retention. */
final class TripPlannerFormHardeningTest extends TestCase
{
    private RecordingTripRequestService $tripRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tripRequests = new RecordingTripRequestService;
        $this->app->instance(TripRequestService::class, $this->tripRequests);
    }

    #[Test]
    public function malformed_nested_values_are_rejected_with_turkish_messages_only(): void
    {
        $fields = ['origin_kind', 'origin_value', 'origin_timezone', 'destination_kind', 'destination_value', 'destination_timezone',
            'journey_type', 'outbound_date_mode', 'outbound_exact_date', 'traveler_count', 'pace_preference', 'origin_selection', 'destination_selection'];

        foreach ($fields as $field) {
            $this->from('/')->post('/planla', [$field => ['nested' => ['x']]] + $this->validForm())
                ->assertRedirect('/')
                ->assertSessionHasErrors($field);

            foreach (session('errors')->all() as $message) {
                self::assertDoesNotMatchRegularExpression('/\b(The|field|must|required|invalid)\b/', $message, "{$field}: {$message}");
            }
        }

        self::assertNull($this->tripRequests->createdInput);
    }

    #[Test]
    public function traveler_count_follows_the_contract_minimum_and_integer_rule(): void
    {
        foreach (['0' => 'En az bir yolcu seçin.', '-2' => 'En az bir yolcu seçin.', '1.5' => 'Yolcu sayısı tam sayı olmalıdır.', 'iki' => 'Yolcu sayısı tam sayı olmalıdır.'] as $value => $message) {
            $this->from('/')->post('/planla', ['traveler_count' => (string) $value] + $this->validForm())
                ->assertSessionHasErrors(['traveler_count' => $message]);
        }
        self::assertNull($this->tripRequests->createdInput);

        $this->post('/planla', ['traveler_count' => '9'] + $this->validForm())->assertRedirect('/planlar/trip-ui-1');
        self::assertSame(9, $this->tripRequests->createdInput['traveler_count']);
    }

    #[Test]
    public function passenger_controls_compute_the_canonical_total_and_reject_zero_or_invalid_counts(): void
    {
        $this->post('/planla', ['adults' => '2', 'children' => '1', 'infants' => '0'] + $this->validForm())
            ->assertRedirect('/planlar/trip-ui-1');
        self::assertSame(3, $this->tripRequests->createdInput['traveler_count']);
        self::assertSame(['adults' => 2, 'children' => 1, 'infants' => 0], $this->tripRequests->createdInput['passenger_composition']);

        foreach ([
            [['adults' => '0', 'children' => '0', 'infants' => '0'], 'adults'],
            [['adults' => '-1', 'children' => '1', 'infants' => '0'], 'adults'],
            [['adults' => '1', 'children' => 'yarim', 'infants' => '0'], 'children'],
        ] as [$counts, $field]) {
            $this->from('/')->post('/planla', $counts + $this->validForm())->assertSessionHasErrors($field);
        }
    }

    #[Test]
    public function unknown_fields_never_reach_the_trip_request(): void
    {
        $this->post('/planla', $this->validForm() + [
            'date_window' => ['exact_date' => '2026-10-15'],
            'status' => 'SEARCHABLE',
            'preferences' => ['budget' => 'yüksek'],
            'origin_iana_timezone' => 'Asia/Tokyo',
        ])->assertRedirect('/planlar/trip-ui-1');

        self::assertSame(
            ['status', 'origin_place', 'destination_place', 'journey_type', 'outbound_date', 'traveler_count', 'passenger_composition', 'preferences'],
            array_keys($this->tripRequests->createdInput),
        );
        self::assertSame('DRAFT', $this->tripRequests->createdInput['status']);
        self::assertNull($this->tripRequests->createdInput['preferences']);
        self::assertSame('Europe/Istanbul', $this->tripRequests->createdInput['origin_place']['iana_timezone']);
    }

    #[Test]
    public function entered_values_survive_a_validation_error(): void
    {
        $this->from('/')->post('/planla', [
            'journey_type' => 'ROUND_TRIP',
            'outbound_date_mode' => 'flexible',
            'outbound_start_date' => '2026-10-15',
            'outbound_end_date' => '2026-10-20',
            'return_date_mode' => 'exact',
            'return_exact_date' => '2026-10-10',
            'traveler_count' => '3',
            'pace_preference' => 'sakin',
        ] + $this->validForm())->assertSessionHasErrors('return_date');

        $content = $this->get('/')->assertOk()->getContent();

        self::assertMatchesRegularExpression('/name="journey_type" value="ROUND_TRIP" checked/', $content);
        self::assertMatchesRegularExpression('/name="outbound_date_mode" value="flexible" checked/', $content);
        self::assertStringContainsString('name="outbound_start_date" value="2026-10-15"', $content);
        self::assertStringContainsString('name="outbound_end_date" value="2026-10-20"', $content);
        self::assertStringContainsString('name="return_exact_date" value="2026-10-10"', $content);
        self::assertStringContainsString('name="traveler_count" value="3"', $content);
        self::assertStringContainsString('name="adults" min="0" step="1" value="1"', $content);
        self::assertStringContainsString('name="pace_preference" value="sakin"', $content);
        self::assertStringContainsString('<input type="hidden" name="origin_value" value="İstanbul"', $content);
        self::assertSame(1, substr_count($content, '<li>Dönüş tarihi, gidiş tarihinden önce olamaz.</li>'));
    }

    /** @return array<string, string> */
    private function validForm(): array
    {
        return [
            'origin_kind' => 'city',
            'origin_value' => 'İstanbul',
            'origin_timezone' => 'Europe/Istanbul',
            'destination_kind' => 'city',
            'destination_value' => 'Roma',
            'destination_timezone' => 'Europe/Rome',
            'journey_type' => 'ONE_WAY',
            'outbound_date_mode' => 'exact',
            'outbound_exact_date' => '2026-10-15',
            'traveler_count' => '1',
        ];
    }
}
