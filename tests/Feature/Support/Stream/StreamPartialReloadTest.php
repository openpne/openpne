<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Stream;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamPartialReloadTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'diary/feed';

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
        Diary::factory()->count(3)->create(['visibility' => Visibility::Members]);
    }

    /**
     * The client keeps the scroll metadata it holds when a partial answer carries none, so a widget
     * reloading a prop of its own leaves the stream's cursor and its generation where they were.
     */
    public function test_a_partial_reload_of_another_prop_reissues_nothing_of_the_stream(): void
    {
        $page = $this->partial('unread');

        $this->assertArrayHasKey('unread', $page['props']);
        $this->assertArrayNotHasKey('diaries', $page['props']);
        $this->assertArrayNotHasKey('streamGeneration', $page['props']);
        $this->assertArrayNotHasKey('scrollProps', $page);
        $this->assertArrayNotHasKey('mergeProps', $page);
    }

    public function test_a_partial_reload_of_the_rows_carries_their_scroll_metadata_and_no_generation(): void
    {
        $page = $this->partial('diaries');

        $this->assertCount(3, $page['props']['diaries']['data']);
        $this->assertArrayHasKey('diaries', $page['scrollProps']);
        $this->assertArrayNotHasKey('streamGeneration', $page['props']);
    }

    /** @return array<string, mixed> */
    private function partial(string $only): array
    {
        $viewer = Member::factory()->create();
        $version = $this->actingAs($viewer)->get('/diary/list')->assertOk()->viewData('page')['version'];

        return $this->actingAs($viewer)->get('/diary/list', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => self::COMPONENT,
            'X-Inertia-Partial-Data' => $only,
        ])->assertOk()->json();
    }
}
