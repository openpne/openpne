<?php

namespace Tests\Feature\Friend\Classic;

use App\Features\Friend\Events\FriendRequestAccepted;
use App\Features\Friend\Events\FriendRequested;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FriendRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_for_every_friend_route(): void
    {
        $this->get('/friend/list')->assertRedirect('/login');
        $this->get('/friend/manage')->assertRedirect('/login');
        $this->get('/friend/link?id=1')->assertRedirect('/login');
        $this->post('/friend/link')->assertRedirect('/login');
        $this->post('/friend/accept')->assertRedirect('/login');
        $this->post('/friend/reject')->assertRedirect('/login');
        $this->get('/friend/unlink/1')->assertRedirect('/login');
        $this->post('/friend/unlink/1')->assertRedirect('/login');
    }

    public function test_list_page_renders_with_friend_body_id(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)->get('/friend/list');

        $response->assertOk();
        $response->assertSee('id="page_friend_list"', false);
        $response->assertSee('Bob');
    }

    public function test_list_page_with_id_query_shows_other_owner_friends(): void
    {
        $alice = Member::factory()->create(['name' => 'Alice']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        $carol = Member::factory()->create(['name' => 'Carol']);
        $this->makeFriends($bob, $carol);

        $response = $this->actingAs($alice)->get("/friend/list?id={$bob->getKey()}");

        $response->assertOk();
        $response->assertSee('Carol');
        $response->assertSee("Bob's friends");
    }

    public function test_list_page_for_unknown_owner_returns_404(): void
    {
        $alice = Member::factory()->create();

        $this->actingAs($alice)->get('/friend/list?id=999999')->assertNotFound();
    }

    public function test_list_page_for_owner_who_blocked_viewer_shows_no_friends(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $carol = Member::factory()->create(['name' => 'Carol']);
        $this->makeFriends($bob, $carol);
        DB::table('member_blocks')->insert([
            'blocker_id' => $bob->getKey(),
            'blocked_id' => $alice->getKey(),
        ]);

        $response = $this->actingAs($alice)->get("/friend/list?id={$bob->getKey()}");

        $response->assertOk();
        $response->assertDontSee('Carol');
    }

    public function test_manage_page_renders_received_and_sent_requests(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $carol = Member::factory()->create(['name' => 'Carol']);
        DB::table('friend_requests')->insert([
            ['requester_id' => $bob->getKey(), 'target_id' => $alice->getKey()],
            ['requester_id' => $alice->getKey(), 'target_id' => $carol->getKey()],
        ]);

        $response = $this->actingAs($alice)->get('/friend/manage');

        $response->assertOk();
        $response->assertSee('id="page_friend_manage"', false);
        $response->assertSee('Bob');
        $response->assertSee('Carol');
    }

    public function test_link_show_page_renders_for_a_target_member(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);

        $response = $this->actingAs($alice)->get("/friend/link?id={$bob->getKey()}");

        $response->assertOk();
        $response->assertSee('id="page_friend_link"', false);
        $response->assertSee('Bob');
    }

    public function test_link_show_page_returns_404_when_target_missing(): void
    {
        $alice = Member::factory()->create();

        $this->actingAs($alice)->get('/friend/link?id=999999')->assertNotFound();
    }

    public function test_link_show_page_returns_404_for_self(): void
    {
        $alice = Member::factory()->create();

        $this->actingAs($alice)->get("/friend/link?id={$alice->getKey()}")->assertNotFound();
    }

    public function test_link_show_page_returns_404_when_target_blocked_viewer(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $bob->getKey(),
            'blocked_id' => $alice->getKey(),
        ]);

        $this->actingAs($alice)->get("/friend/link?id={$bob->getKey()}")->assertNotFound();
    }

    public function test_link_show_page_redirects_to_list_when_already_friends(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        $this->makeFriends($alice, $bob);

        $this->actingAs($alice)->get("/friend/link?id={$bob->getKey()}")
            ->assertRedirect(route('friend.list'));
    }

    public function test_link_show_page_redirects_to_manage_when_request_already_pending(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('friend_requests')->insert([
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);

        $this->actingAs($alice)->get("/friend/link?id={$bob->getKey()}")
            ->assertRedirect(route('friend.manage'));
    }

    public function test_unlink_show_page_renders(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)->get("/friend/unlink/{$bob->getKey()}");

        $response->assertOk();
        $response->assertSee('id="page_friend_unlink"', false);
        $response->assertSee('Bob');
    }

    public function test_unlink_show_page_returns_404_when_not_friends(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();

        $this->actingAs($alice)->get("/friend/unlink/{$bob->getKey()}")->assertNotFound();
    }

    public function test_unlink_show_page_returns_404_for_self(): void
    {
        $alice = Member::factory()->create();

        $this->actingAs($alice)->get("/friend/unlink/{$alice->getKey()}")->assertNotFound();
    }

    public function test_submitting_link_creates_a_pending_request_and_redirects_with_flash(): void
    {
        Event::fake([FriendRequested::class]);
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();

        $response = $this->actingAs($alice)->post('/friend/link', ['target_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.list'));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('friend_requests', [
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);
        Event::assertDispatched(FriendRequested::class);
    }

    public function test_submitting_link_for_self_redirects_with_flash_error(): void
    {
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)->post('/friend/link', ['target_id' => $alice->getKey()]);

        $response->assertRedirect(route('friend.list'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('friend_requests', 0);
    }

    public function test_submitting_link_for_already_friends_redirects_with_flash_error(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)->post('/friend/link', ['target_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.list'));
        $response->assertSessionHas('error');
    }

    public function test_submitting_link_to_blocking_member_flashes_a_privacy_safe_message(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $bob->getKey(),
            'blocked_id' => $alice->getKey(),
        ]);

        $response = $this->actingAs($alice)->post('/friend/link', ['target_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.list'));
        $response->assertSessionHas('error', 'This member is unavailable.');
        $this->assertDatabaseCount('friend_requests', 0);
    }

    public function test_submitting_link_requires_target_id(): void
    {
        $alice = Member::factory()->create();

        $this->actingAs($alice)->post('/friend/link')->assertSessionHasErrors('target_id');
    }

    public function test_accept_creates_friendship_and_redirects(): void
    {
        Event::fake([FriendRequestAccepted::class]);
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('friend_requests')->insert([
            'requester_id' => $bob->getKey(),
            'target_id' => $alice->getKey(),
        ]);

        $response = $this->actingAs($alice)->post('/friend/accept', ['requester_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.list'));
        $response->assertSessionHas('status');
        $this->assertDatabaseCount('friend_requests', 0);
        $this->assertDatabaseCount('friendships', 2);
        Event::assertDispatched(FriendRequestAccepted::class);
    }

    public function test_accept_without_pending_request_flashes_error(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();

        $response = $this->actingAs($alice)->post('/friend/accept', ['requester_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.manage'));
        $response->assertSessionHas('error');
    }

    public function test_reject_deletes_request_and_redirects_to_manage(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('friend_requests')->insert([
            'requester_id' => $bob->getKey(),
            'target_id' => $alice->getKey(),
        ]);

        $response = $this->actingAs($alice)->post('/friend/reject', ['requester_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.manage'));
        $response->assertSessionHas('status');
        $this->assertDatabaseCount('friend_requests', 0);
    }

    public function test_unlink_removes_friendship_and_redirects(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)->post("/friend/unlink/{$bob->getKey()}");

        $response->assertRedirect(route('friend.list'));
        $response->assertSessionHas('status');
        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_unlink_when_not_friends_flashes_error(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();

        $response = $this->actingAs($alice)->post("/friend/unlink/{$bob->getKey()}");

        $response->assertRedirect(route('friend.list'));
        $response->assertSessionHas('error');
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
