<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A view that links an unregistered route name fails every page using it with RouteNotFoundException (HTTP 500).
 * Production 2026-09-30: the new layout's route('trip-requests.mine') was served against a route table without it.
 */
final class ViewRouteReferencesTest extends TestCase
{
    #[Test]
    public function every_route_name_referenced_by_a_view_is_registered(): void
    {
        $referenced = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all("/\broute\('([^']+)'/", $file->getContents(), $matches);
            foreach ($matches[1] as $name) {
                $referenced[$name][] = $file->getRelativePathname();
            }
        }

        self::assertArrayHasKey('trip-requests.mine', $referenced);
        foreach ($referenced as $name => $views) {
            self::assertTrue(Route::has($name), "Route [{$name}] is referenced by ".implode(', ', array_unique($views)).' but not registered.');
        }
    }

    #[Test]
    public function layout_pages_render_with_the_taleplerim_navigation(): void
    {
        $this->get('/')->assertOk()->assertSee('href="'.url('/taleplerim').'"', false);
        $this->get('/taleplerim')->assertOk();
    }
}
