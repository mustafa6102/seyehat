<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The 54 analysis-defined screens: one catalog and one task-oriented, presentation-safe page per story. */
final class CapabilityScreensTest extends TestCase
{
    #[Test]
    public function the_catalog_lists_every_screen_with_its_availability_in_words(): void
    {
        $stories = config('frontend_screens.stories');
        $content = (string) $this->get('/ozellikler')->assertOk()->getContent();

        self::assertCount(54, $stories);
        foreach ($stories as $story) {
            self::assertStringContainsString(route('capabilities.show', ['storyId' => $story['id']]), $content);
        }
        self::assertStringContainsString('<dd>54</dd>', $content);
        self::assertStringContainsString('Sunum ekranı', $content);
        self::assertStringContainsString('Kullanılabilir akış', $content);
    }

    #[Test]
    public function every_screen_renders_a_traveler_facing_state_and_keeps_analysis_as_reference(): void
    {
        foreach (config('frontend_screens.stories') as $story) {
            $response = $this->get("/ozellikler/{$story['id']}")->assertOk()
                ->assertSee('Bu ekranda ne bulacaksınız?')
                ->assertSee('Analiz, kurallar ve kabul kontrolleri');
            foreach ($story['tasks'] as $task) {
                $response->assertSee($task);
            }
            foreach ($story['checks'] as $check) {
                $response->assertSee($check['then']);
            }

            if ($story['route'] === null) {
                $response->assertSee('Bu özellik henüz işlem yapmıyor')
                    ->assertSee('Planlanıyor')
                    ->assertDontSee('Akışa git');
            } else {
                $response->assertSee('Bu özellik kullanılabilir')
                    ->assertSee('Akışa git')
                    ->assertSee(route($story['route']), false);
            }
        }
    }

    #[Test]
    public function journey_navigation_reaches_every_user_facing_screen_and_hides_internal_capabilities(): void
    {
        $stories = collect(config('frontend_screens.stories'));
        $categoryStoryIds = collect(config('frontend_screens.categoryStories'))->flatten()->values();
        $userFacingIds = $stories->filter(fn (array $story): bool => $story['user_facing'])->keys()->values();
        $internalIds = $stories->reject(fn (array $story): bool => $story['user_facing'])->keys()->values();

        self::assertCount(54, $stories);
        self::assertSame($userFacingIds->sort()->values()->all(), $categoryStoryIds->sort()->values()->all());
        self::assertContainsOnly('string', $stories->pluck('classification')->all());
        self::assertSame(13, $stories->whereIn('classification', ['main_navigation', 'category_subpage'])->count());
        self::assertSame(30, $stories->whereIn('classification', ['contextual', 'trip_context'])->count());
        self::assertSame(11, $stories->where('classification', 'internal')->count());

        $hub = (string) $this->get('/seyahat-rehberi')->assertOk()->getContent();
        foreach (config('frontend_screens.categories') as $categoryId => $category) {
            self::assertStringContainsString(route('journey.category', ['categoryId' => $categoryId]), $hub);
            $categoryHtml = (string) $this->get("/seyahat-rehberi/{$categoryId}")->assertOk()->getContent();
            foreach (config("frontend_screens.categoryStories.{$categoryId}") as $storyId) {
                self::assertStringContainsString(route('capabilities.show', ['storyId' => $storyId]), $categoryHtml);
            }
        }
        foreach ($internalIds as $storyId) {
            self::assertStringNotContainsString(route('capabilities.show', ['storyId' => $storyId]), $hub);
        }

        $this->get('/seyahat-rehberi/bilinmeyen')->assertNotFound();
    }

    #[Test]
    public function acceptance_criteria_are_split_into_situation_action_and_expected_result(): void
    {
        $check = config('frontend_screens.stories.FOOD-001.checks.0');

        self::assertSame('restoran öğle saatinde kapalı', $check['given']);
        self::assertSame('adaylar oluşturulur', $check['when']);
        self::assertSame('o pencereye yerleştirilmez.', $check['then']);
        $this->get('/ozellikler/BILINMEYEN-001')->assertNotFound();
    }
}
