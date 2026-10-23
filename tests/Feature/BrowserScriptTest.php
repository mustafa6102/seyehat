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
        self::assertMatchesRegularExpression('/ℹ pass 21\b/u', $result->output());
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
