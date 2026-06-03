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
        // Search shares the feed template (OpenPNE 3's listSuccess.php serves both).
        $response->assertSee('id="diary_feed"', false);
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

    public function test_empty_search_pages_through_the_list_url(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->count(25)->create(['visibility' => Visibility::Members]);

        $response = $this->actingAs($viewer)->get('/diary/search');

        $response->assertOk();
        // OpenPNE 3's forward-to-list pager targets @diary_list, not /diary/search.
        $response->assertSee('/diary/list?page=2');
        $response->assertDontSee('/diary/search?page=2');
    }
}
