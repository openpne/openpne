<?php

namespace Tests\Feature\Diary\Classic;

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
        $this->get('/diary/search')->assertRedirect('/login');
    }

    public function test_search_page_renders_form_with_body_id(): void
    {
        $viewer = Member::factory()->create();

        $response = $this->actingAs($viewer)->get('/diary/search?keyword=anything');

        $response->assertOk();
        $response->assertSee('id="page_diary_search"', false);
        $response->assertSee('id="diary_search"', false);
        $response->assertSee('name="keyword"', false);
        $response->assertSee('Search Results');
    }

    public function test_keyword_filters_the_results(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->create([
            'title' => 'Laravel tips', 'visibility' => Visibility::Members,
        ]);
        Diary::factory()->create([
            'title' => 'Cooking notes', 'visibility' => Visibility::Members,
        ]);

        $response = $this->actingAs($viewer)->get('/diary/search?keyword=laravel');

        $response->assertOk();
        $response->assertSee('Laravel tips');
        $response->assertDontSee('Cooking notes');
        // The query stays in the input so the search can be refined.
        $response->assertSee('value="laravel"', false);
    }

    public function test_empty_keyword_renders_the_list_page_with_its_body_id(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->create([
            'title' => 'Recent entry', 'visibility' => Visibility::Members,
        ]);

        $response = $this->actingAs($viewer)->get('/diary/search');

        $response->assertOk();
        $response->assertSee('Recent entry');
        // OpenPNE 3 forwards an empty search to the list: list body id + heading, form retained.
        $response->assertSee('id="page_diary_list"', false);
        $response->assertSee('Recently Posted');
        $response->assertSee('name="keyword"', false);
    }
}
