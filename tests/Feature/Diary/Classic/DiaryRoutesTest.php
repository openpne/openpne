<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiaryRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_for_every_diary_route(): void
    {
        $this->get('/diary/listMember')->assertRedirect('/login');
        $this->get('/diary/new')->assertRedirect('/login');
        $this->post('/diary/create')->assertRedirect('/login');
        $this->get('/diary/edit/1')->assertRedirect('/login');
        $this->post('/diary/update/1')->assertRedirect('/login');
        $this->get('/diary/deleteConfirm/1')->assertRedirect('/login');
        $this->post('/diary/delete/1')->assertRedirect('/login');
        $this->get('/diary/1')->assertRedirect('/login');
    }

    // listMember ----------------------------------------------------------------

    public function test_list_member_page_renders_own_archive_with_body_id(): void
    {
        $member = Member::factory()->create();
        Diary::factory()->create(['member_id' => $member->getKey(), 'title' => 'My Entry']);

        $response = $this->actingAs($member)->get('/diary/listMember');

        $response->assertOk();
        // OpenPNE 3 emits page_{module}_{action}; the action is listMember, not list.
        $response->assertSee('id="page_diary_listMember"', false);
        $response->assertSee('My Entry');
    }

    public function test_list_member_page_shows_other_members_archive_with_id_param(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $bob->getKey(),
            'title' => 'Bobs Entry',
            'visibility' => Visibility::Members,
        ]);

        $response = $this->actingAs($alice)->get("/diary/listMember/{$bob->getKey()}");

        $response->assertOk();
        $response->assertSee('Bobs Entry');
    }

    public function test_list_member_hides_private_diary_from_non_owner(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        Diary::factory()->private()->create([
            'member_id' => $bob->getKey(),
            'title' => 'Secret',
        ]);

        $response = $this->actingAs($alice)->get("/diary/listMember/{$bob->getKey()}");

        $response->assertOk();
        $response->assertDontSee('Secret');
    }

    public function test_list_member_empty_when_owner_blocks_viewer(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        Diary::factory()->create(['member_id' => $bob->getKey(), 'title' => 'Bob Entry']);
        DB::table('member_blocks')->insert([
            'blocker_id' => $bob->getKey(),
            'blocked_id' => $alice->getKey(),
        ]);

        $response = $this->actingAs($alice)->get("/diary/listMember/{$bob->getKey()}");

        $response->assertOk();
        $response->assertDontSee('Bob Entry');
    }

    public function test_list_member_returns_404_for_unknown_owner(): void
    {
        $alice = Member::factory()->create();

        $this->actingAs($alice)->get('/diary/listMember/999999')->assertNotFound();
    }

    // show ----------------------------------------------------------------------

    public function test_show_page_renders_with_body_id(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey(), 'title' => 'Hello']);

        $response = $this->actingAs($member)->get("/diary/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSee('id="page_diary_show"', false);
        $response->assertSee('Hello');
    }

    public function test_show_returns_404_for_private_diary_viewed_by_non_owner(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->private()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->get("/diary/{$diary->getKey()}")->assertNotFound();
    }

    public function test_show_returns_404_when_owner_blocks_viewer(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $bob->getKey()]);
        DB::table('member_blocks')->insert([
            'blocker_id' => $bob->getKey(),
            'blocked_id' => $alice->getKey(),
        ]);

        $this->actingAs($alice)->get("/diary/{$diary->getKey()}")->assertNotFound();
    }

    // new / store ---------------------------------------------------------------

    public function test_new_page_renders_with_body_id(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/diary/new');

        $response->assertOk();
        $response->assertSee('id="page_diary_new"', false);
    }

    public function test_store_creates_diary_and_redirects_with_flash(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/diary/create', [
            'title' => 'A new diary',
            'body' => 'Content here',
            'visibility' => '1',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('diaries', [
            'member_id' => $member->getKey(),
            'title' => 'A new diary',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/diary/create')->assertSessionHasErrors(['title', 'body', 'visibility']);
    }

    public function test_store_rejects_open_visibility(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/diary/create', [
            'title' => 'Title',
            'body' => 'Body',
            'visibility' => '0',
        ])->assertSessionHasErrors('visibility');
    }

    public function test_store_accepts_long_title_without_validation_error(): void
    {
        // OpenPNE 3 title is TEXT with no limit; validation must not cap it (no max:N).
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/diary/create', [
            'title' => str_repeat('あ', 500),
            'body' => 'Body',
            'visibility' => '1',
        ]);

        $response->assertSessionDoesntHaveErrors('title');
        $response->assertRedirect();
    }

    // edit / update -------------------------------------------------------------

    public function test_edit_page_renders_with_body_id(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->get("/diary/edit/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSee('id="page_diary_edit"', false);
    }

    public function test_edit_page_returns_404_for_non_owner(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->get("/diary/edit/{$diary->getKey()}")->assertNotFound();
    }

    public function test_update_changes_diary_and_redirects(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->post("/diary/update/{$diary->getKey()}", [
            'title' => 'Updated title',
            'body' => 'Updated body',
            'visibility' => '2',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('diaries', ['id' => $diary->getKey(), 'title' => 'Updated title']);
    }

    public function test_update_returns_404_for_non_owner(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->post("/diary/update/{$diary->getKey()}", [
            'title' => 'Hacked',
            'body' => 'body',
            'visibility' => '1',
        ])->assertNotFound();
    }

    public function test_update_returns_404_for_non_owner_even_with_invalid_payload(): void
    {
        // Invalid payload must not leak diary existence via validation errors.
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->post("/diary/update/{$diary->getKey()}")->assertNotFound();
    }

    // delete --------------------------------------------------------------------

    public function test_delete_confirm_page_renders_with_body_id(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->get("/diary/deleteConfirm/{$diary->getKey()}");

        $response->assertOk();
        // OpenPNE 3 action is deleteConfirm; the confirm page's body id follows it.
        $response->assertSee('id="page_diary_deleteConfirm"', false);
    }

    public function test_delete_confirm_returns_404_for_non_owner(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->get("/diary/deleteConfirm/{$diary->getKey()}")->assertNotFound();
    }

    public function test_delete_removes_diary_and_redirects_with_flash(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->post("/diary/delete/{$diary->getKey()}");

        $response->assertRedirect(route('diary.list_member'));
        $response->assertSessionHas('status');
        $this->assertDatabaseMissing('diaries', ['id' => $diary->getKey()]);
    }

    public function test_delete_returns_404_for_non_owner(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->post("/diary/delete/{$diary->getKey()}")->assertNotFound();
    }
}
