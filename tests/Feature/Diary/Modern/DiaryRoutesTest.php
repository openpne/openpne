<?php

namespace Tests\Feature\Diary\Modern;

use App\Models\Diary;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_for_modern_routes(): void
    {
        $this->get('/m/diary/listMember')->assertRedirect('/login');
        $this->get('/m/diary/new')->assertRedirect('/login');
        $this->post('/m/diary/create')->assertRedirect('/login');
        $this->get('/m/diary/1')->assertRedirect('/login');
    }

    public function test_modern_list_member_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/m/diary/listMember')
            ->assertInertia(fn ($page) => $page->component('diary/list'));
    }

    public function test_modern_status_fallback_renders_classic_with_op3_body_id(): void
    {
        // When diary is not native, a /m/* route falls back to Classic; the body id must
        // still be the OpenPNE 3 hook derived from the canonical route, not empty.
        config()->set('features.diary.modern_status', 'fallback');
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/m/diary/listMember');

        $response->assertOk();
        $response->assertSee('id="page_diary_listMember"', false);
    }

    public function test_modern_new_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/m/diary/new')
            ->assertInertia(fn ($page) => $page->component('diary/new'));
    }

    public function test_modern_show_renders_inertia_component_with_diary_props(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/m/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('diary/show')
                ->has('diary.id')
                ->has('diary.title')
                ->has('diary.body')
                ->has('diary.visibility')
                ->where('diary.id', $diary->getKey())
            );
    }

    public function test_modern_show_returns_404_for_non_viewable_diary(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->private()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->get("/m/diary/{$diary->getKey()}")->assertNotFound();
    }

    public function test_modern_edit_renders_inertia_component_for_owner(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/m/diary/edit/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page->component('diary/edit'));
    }

    public function test_modern_store_creates_diary_and_redirects_to_modern_show(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/m/diary/create', [
            'title' => 'Modern diary',
            'body' => 'Content',
            'visibility' => '1',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('diaries', ['title' => 'Modern diary']);
        // Redirect should point to the modern show route (/m/diary/{id}).
        $this->assertStringContainsString('/m/diary/', $response->headers->get('Location') ?? '');
    }

    public function test_modern_delete_removes_diary_and_redirects_to_modern_list(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->post("/m/diary/delete/{$diary->getKey()}");

        $response->assertRedirect(route('diary.modern.list_member'));
        $this->assertDatabaseMissing('diaries', ['id' => $diary->getKey()]);
    }

    public function test_visibility_slug_is_string_in_inertia_props(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/m/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.visibility', 'members')
            );
    }
}
