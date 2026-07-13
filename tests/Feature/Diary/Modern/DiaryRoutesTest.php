<?php

namespace Tests\Feature\Diary\Modern;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/diary/listMember')->assertRedirect('/login');
        $this->get('/diary/new')->assertRedirect('/login');
        $this->post('/diary/create')->assertRedirect('/login');
        $this->get('/diary/1')->assertRedirect('/login');
    }

    public function test_modern_list_member_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/diary/listMember')
            ->assertInertia(fn ($page) => $page->component('diary/list'));
    }

    public function test_modern_list_member_rich_row_carries_a_thumbnail_url(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);

        $this->actingAs($member)
            ->get('/diary/listMember')
            ->assertInertia(fn ($page) => $page
                ->where('diaries.data.0.thumbnailUrl', fn ($url) => is_string($url) && $url !== '')
            );
    }

    public function test_modern_status_fallback_renders_classic_with_op3_body_id(): void
    {
        // When diary is not native, the canonical route falls back to Classic; the body id
        // must still be the OpenPNE 3 hook, not empty.
        config()->set('features.diary.modern_status', 'fallback');
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/diary/listMember');

        $response->assertOk();
        $response->assertSee('id="page_diary_listMember"', false);
    }

    public function test_modern_new_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/diary/new')
            ->assertInertia(fn ($page) => $page->component('diary/new'));
    }

    public function test_modern_show_renders_inertia_component_with_diary_props(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('diary/show')
                ->has('diary.id')
                ->has('diary.title')
                ->has('diary.body')
                ->has('diary.visibility')
                ->where('diary.id', $diary->getKey())
                // The byline links to the author's profile and shows their avatar.
                ->where('diary.author.id', $member->getKey())
                ->has('diary.author.imageUrl')
            );
    }

    public function test_modern_show_returns_404_for_non_viewable_diary(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->private()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->get("/diary/{$diary->getKey()}")->assertNotFound();
    }

    public function test_modern_show_carries_older_and_newer_neighbors(): void
    {
        $author = Member::factory()->create();
        $older = Diary::factory()->create(['member_id' => $author->getKey(), 'title' => 'Older']);
        $middle = Diary::factory()->create(['member_id' => $author->getKey(), 'title' => 'Middle']);
        $newer = Diary::factory()->create(['member_id' => $author->getKey(), 'title' => 'Newer']);

        $this->actingAs($author)
            ->get("/diary/{$middle->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('older.id', $older->getKey())
                ->where('older.title', 'Older')
                ->has('older.createdAt')
                ->where('newer.id', $newer->getKey())
                ->where('newer.title', 'Newer')
            );
    }

    public function test_modern_show_neighbors_are_null_at_the_timeline_ends(): void
    {
        $author = Member::factory()->create();
        $oldest = Diary::factory()->create(['member_id' => $author->getKey()]);
        $newest = Diary::factory()->create(['member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->get("/diary/{$oldest->getKey()}")
            ->assertInertia(fn ($page) => $page->where('older', null)->where('newer.id', $newest->getKey()));

        $this->actingAs($author)
            ->get("/diary/{$newest->getKey()}")
            ->assertInertia(fn ($page) => $page->where('older.id', $oldest->getKey())->where('newer', null));
    }

    public function test_modern_show_neighbors_skip_a_diary_the_viewer_cannot_see(): void
    {
        [$author, $viewer] = Member::factory()->count(2)->create()->all();
        $first = Diary::factory()->create(['member_id' => $author->getKey()]);
        Diary::factory()->private()->create(['member_id' => $author->getKey()]);
        $third = Diary::factory()->create(['member_id' => $author->getKey()]);

        // The viewer (not the author, not a friend) cannot see the private entry between the two
        // members-visible ones, so the newer neighbor skips it — the props inherit AdjacentDiaries' viewer scope.
        $this->actingAs($viewer)
            ->get("/diary/{$first->getKey()}")
            ->assertInertia(fn ($page) => $page->where('newer.id', $third->getKey()));
    }

    public function test_modern_edit_renders_inertia_component_for_owner(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/diary/edit/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page->component('diary/edit'));
    }

    public function test_modern_store_creates_diary_and_redirects_to_show(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/diary/create', [
            'title' => 'Modern diary',
            'body' => 'Content',
            'visibility' => '1',
        ]);

        $this->assertDatabaseHas('diaries', ['title' => 'Modern diary']);
        $diary = Diary::query()->where('title', 'Modern diary')->firstOrFail();
        $response->assertRedirect(route('diary.show', $diary));
    }

    public function test_modern_delete_removes_diary_and_redirects_to_list(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->post("/diary/delete/{$diary->getKey()}");

        $response->assertRedirect(route('diary.list_member'));
        $this->assertDatabaseMissing('diaries', ['id' => $diary->getKey()]);
    }

    public function test_modern_delete_returns_404_for_a_non_owner(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs(Member::factory()->create())
            ->post("/diary/delete/{$diary->getKey()}")
            ->assertNotFound();
        $this->assertDatabaseHas('diaries', ['id' => $diary->getKey()]);
    }

    public function test_visibility_slug_is_string_in_inertia_props(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.visibility', 'members')
            );
    }
}
