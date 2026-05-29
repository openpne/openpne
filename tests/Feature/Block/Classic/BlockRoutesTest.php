<?php

namespace Tests\Feature\Block\Classic;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BlockRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/block/list')->assertRedirect('/login');
        $this->get('/member/config?category=accessBlock')->assertRedirect('/login');
    }

    public function test_list_renders_blocks_and_add_form(): void
    {
        $member = Member::factory()->create();
        $blocked = Member::factory()->create(['name' => 'Mallory']);
        DB::table('member_blocks')->insert([
            'blocker_id' => $member->getKey(),
            'blocked_id' => $blocked->getKey(),
        ]);

        $response = $this->actingAs($member)->get('/block/list');

        $response->assertOk();
        $response->assertSee('id="page_block_list"', false);
        $response->assertSee('Mallory');
        $response->assertSee('name="id"', false);
    }

    public function test_show_add_renders_confirmation(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create(['name' => 'Trent']);

        $response = $this->actingAs($member)->get('/block/add?id='.$target->getKey());

        $response->assertOk();
        $response->assertSee('Trent');
    }

    public function test_show_add_404_for_self(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/block/add?id='.$member->getKey())->assertNotFound();
    }

    public function test_show_add_404_when_viewer_already_blocks_target(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $member->getKey(),
            'blocked_id' => $target->getKey(),
        ]);

        $this->actingAs($member)->get('/block/add?id='.$target->getKey())->assertNotFound();
    }

    public function test_submit_add_self_flashes_error(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/block/add', [
            'target_id' => $member->getKey(),
        ]);

        $response->assertRedirect(route('block.list'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('member_blocks', 0);
    }

    public function test_submit_add_creates_block(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();

        $response = $this->actingAs($member)->post('/block/add', [
            'target_id' => $target->getKey(),
        ]);

        $response->assertRedirect(route('block.list'));
        $this->assertDatabaseHas('member_blocks', [
            'blocker_id' => $member->getKey(),
            'blocked_id' => $target->getKey(),
        ]);
    }

    public function test_submit_add_twice_flashes_error(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $member->getKey(),
            'blocked_id' => $target->getKey(),
        ]);

        $response = $this->actingAs($member)->post('/block/add', [
            'target_id' => $target->getKey(),
        ]);

        $response->assertRedirect(route('block.list'));
        $response->assertSessionHas('error');
    }

    public function test_show_remove_renders_confirmation(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create(['name' => 'Oscar']);
        DB::table('member_blocks')->insert([
            'blocker_id' => $member->getKey(),
            'blocked_id' => $target->getKey(),
        ]);

        $response = $this->actingAs($member)->get('/block/remove/'.$target->getKey());

        $response->assertOk();
        $response->assertSee('Oscar');
    }

    public function test_show_remove_404_when_not_blocked(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();

        $this->actingAs($member)->get('/block/remove/'.$target->getKey())->assertNotFound();
    }

    public function test_submit_remove_deletes_block(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $member->getKey(),
            'blocked_id' => $target->getKey(),
        ]);

        $response = $this->actingAs($member)->post('/block/remove/'.$target->getKey());

        $response->assertRedirect(route('block.list'));
        $this->assertDatabaseMissing('member_blocks', [
            'blocker_id' => $member->getKey(),
            'blocked_id' => $target->getKey(),
        ]);
    }

    public function test_legacy_access_block_url_redirects_to_block_list(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/member/config?category=accessBlock');

        $response->assertStatus(302);
        $response->assertRedirect(route('block.list'));
    }

    public function test_member_config_without_access_block_category_is_404(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=profile')->assertNotFound();
    }
}
