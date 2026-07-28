<?php

namespace Tests\Feature\Diary\Modern;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiarySearchRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_a_guest_searches_only_web_public_entries(): void
    {
        Diary::factory()->create(['title' => 'Open note', 'visibility' => Visibility::Open]);
        Diary::factory()->create(['title' => 'Members note', 'visibility' => Visibility::Members]);

        $this->get('/diary/search?keyword=note')->assertInertia(fn ($page) => $page
            ->component('diary/feed')
            ->has('diaries.data', 1)
            ->where('diaries.data.0.title', 'Open note')
        );
    }

    public function test_search_renders_inertia_with_keyword_and_filtered_results(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->create([
            'title' => 'Laravel tips', 'visibility' => Visibility::Members,
        ]);
        Diary::factory()->create([
            'title' => 'Cooking notes', 'visibility' => Visibility::Members,
        ]);

        // Search shares the feed component.
        $this->actingAs($viewer)->get('/diary/search?keyword=laravel')
            ->assertInertia(fn ($page) => $page
                ->component('diary/feed')
                ->where('variant', 'search')
                ->where('keyword', 'laravel')
                ->where('hasKeyword', true)
                ->has('diaries.data', 1)
                ->where('diaries.data.0.title', 'Laravel tips')
            );
    }
}
