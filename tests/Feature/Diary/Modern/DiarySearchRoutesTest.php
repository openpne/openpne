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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/m/diary/search')->assertRedirect('/login');
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

        $this->actingAs($viewer)->get('/m/diary/search?keyword=laravel')
            ->assertInertia(fn ($page) => $page
                ->component('diary/search')
                ->where('keyword', 'laravel')
                ->where('hasKeyword', true)
                ->has('diaries.data', 1)
                ->where('diaries.data.0.title', 'Laravel tips')
            );
    }
}
