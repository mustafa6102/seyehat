<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Runs the dependency-free node:test suites for public/assets scripts (tests/js) as part of PHPUnit. */
final class BrowserScriptTest extends TestCase
{
    #[Test]
    public function flight_results_offer_and_reprice_rendering_suite_passes(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            self::markTestSkipped('Node.js is not available.');
        }

        $result = Process::path(base_path())->timeout(120)->run(['node', '--test', 'tests/js/flight-ui.test.mjs']);

        self::assertTrue($result->successful(), $result->output().$result->errorOutput());
        self::assertMatchesRegularExpression('/ℹ pass 22\b/u', $result->output());
        self::assertMatchesRegularExpression('/ℹ fail 0\b/u', $result->output());
    }

    #[Test]
    public function accommodation_search_selection_and_unknown_price_suite_passes(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            self::markTestSkipped('Node.js is not available.');
        }

        $result = Process::path(base_path())->timeout(120)->run(['node', '--test', 'tests/js/accommodation-ui.test.mjs']);

        self::assertTrue($result->successful(), $result->output().$result->errorOutput());
        self::assertMatchesRegularExpression('/ℹ pass 2\b/u', $result->output());
        self::assertMatchesRegularExpression('/ℹ fail 0\b/u', $result->output());
    }

    #[Test]
    public function trip_planner_form_behaviour_suite_passes(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            self::markTestSkipped('Node.js is not available.');
        }

        $result = Process::path(base_path())->timeout(120)->run(['node', '--test', 'tests/js/trip-planner-ui.test.mjs']);

        self::assertTrue($result->successful(), $result->output().$result->errorOutput());
        self::assertMatchesRegularExpression('/ℹ pass 6\b/u', $result->output());
        self::assertMatchesRegularExpression('/ℹ fail 0\b/u', $result->output());
    }

    #[Test]
    public function day_plan_behaviour_suite_passes(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            self::markTestSkipped('Node.js is not available.');
        }

        $result = Process::path(base_path())->timeout(120)->run(['node', '--test', 'tests/js/plan-days.test.mjs']);

        self::assertTrue($result->successful(), $result->output().$result->errorOutput());
        self::assertMatchesRegularExpression('/ℹ pass 6\b/u', $result->output());
        self::assertMatchesRegularExpression('/ℹ fail 0\b/u', $result->output());
    }

    #[Test]
    public function contextual_how_to_guide_suite_passes(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            self::markTestSkipped('Node.js is not available.');
        }

        $result = Process::path(base_path())->timeout(120)->run(['node', '--test', 'tests/js/how-guide.test.mjs']);

        self::assertTrue($result->successful(), $result->output().$result->errorOutput());
        self::assertMatchesRegularExpression('/ℹ pass 4\b/u', $result->output());
        self::assertMatchesRegularExpression('/ℹ fail 0\b/u', $result->output());
    }

    #[Test]
    public function poi_closed_place_recommendation_suite_passes(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            self::markTestSkipped('Node.js is not available.');
        }

        $result = Process::path(base_path())->timeout(120)->run(['node', '--test', 'tests/js/poi-ui.test.mjs']);

        self::assertTrue($result->successful(), $result->output().$result->errorOutput());
        self::assertMatchesRegularExpression('/ℹ pass 2\b/u', $result->output());
        self::assertMatchesRegularExpression('/ℹ fail 0\b/u', $result->output());
    }

    #[Test]
    public function event_availability_and_fixed_plan_action_suite_passes(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            self::markTestSkipped('Node.js is not available.');
        }

        $result = Process::path(base_path())->timeout(120)->run(['node', '--test', 'tests/js/event-ui.test.mjs']);

        self::assertTrue($result->successful(), $result->output().$result->errorOutput());
        self::assertMatchesRegularExpression('/ℹ pass 3\b/u', $result->output());
        self::assertMatchesRegularExpression('/ℹ fail 0\b/u', $result->output());
    }

    #[Test]
    public function shared_ui_behaviour_suite_passes(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            self::markTestSkipped('Node.js is not available.');
        }

        $result = Process::path(base_path())->timeout(120)->run(['node', '--test', 'tests/js/ui.test.mjs']);

        self::assertTrue($result->successful(), $result->output().$result->errorOutput());
        self::assertMatchesRegularExpression('/ℹ pass 5\b/u', $result->output());
        self::assertMatchesRegularExpression('/ℹ fail 0\b/u', $result->output());
    }
}
