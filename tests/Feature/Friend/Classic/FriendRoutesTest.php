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
        $this->get('/friend/requests')->assertRedirect('/login');
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

    public function test_list_page_renders_the_openpne3_photo_table_with_counts_and_a_pager(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $carol = Member::factory()->create(['name' => 'Carol']);
        $this->makeFriends($alice, $bob);
        $this->makeFriends($bob, $carol);

        $response = $this->actingAs($alice)->get('/friend/list');

        $response->assertOk();
        $response->assertSee('<tr class="photo">', false);
        $response->assertSee('<tr class="text">', false);
        // Bob's own friend count (Alice and Carol) rides the label, as OpenPNE 3 getNameAndCount did.
        $response->assertSee('>Bob (2)</a>', false);
        $response->assertSee('class="pagerRelative"', false);
        // OpenPNE 3's list is a pure photo table: unlinking lives on friend/manage.
        $response->assertDontSee('href="'.route('friend.unlink.show', $bob).'"', false);
    }

    public function test_list_pager_keeps_the_id_subject_across_pages(): void
    {
        $alice = Member::factory()->create(['name' => 'Alice']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        foreach (Member::factory()->count(21)->create() as $friend) {
            $this->makeFriends($bob, $friend);
        }

        $first = $this->actingAs($alice)->get("/friend/list?id={$bob->getKey()}");
        $first->assertOk();
        $first->assertSee('id='.$bob->getKey().'&amp;page=2', false);

        // Page 2 still shows Bob's 21-friend list (the viewer's own list has none).
        $second = $this->actingAs($alice)->get("/friend/list?id={$bob->getKey()}&page=2");
        $second->assertOk();
        $second->assertSee('21 - 21 of 21');
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

    public function test_list_page_for_owner_who_blocked_viewer_returns_404(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $carol = Member::factory()->create(['name' => 'Carol']);
        $this->makeFriends($bob, $carol);
        DB::table('member_blocks')->insert([
            'blocker_id' => $bob->getKey(),
            'blocked_id' => $alice->getKey(),
        ]);

        // The whole page is denied (MemberPolicy::access), not rendered empty.
        $this->actingAs($alice)->get("/friend/list?id={$bob->getKey()}")->assertNotFound();
    }

    public function test_requests_page_renders_received_and_sent_requests(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $carol = Member::factory()->create(['name' => 'Carol']);
        DB::table('friend_requests')->insert([
            ['requester_id' => $bob->getKey(), 'target_id' => $alice->getKey()],
            ['requester_id' => $alice->getKey(), 'target_id' => $carol->getKey()],
        ]);

        $response = $this->actingAs($alice)->get('/friend/requests');

        $response->assertOk();
        $response->assertSee('id="page_friend_requests"', false);
        $response->assertSee('Bob');
        $response->assertSee('Carol');
    }

    public function test_requests_page_draws_the_openpne3_manage_list(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $carol = Member::factory()->create(['name' => 'Carol']);
        DB::table('friend_requests')->insert([
            ['requester_id' => $bob->getKey(), 'target_id' => $alice->getKey()],
            ['requester_id' => $alice->getKey(), 'target_id' => $carol->getKey()],
        ]);

        $response = $this->actingAs($alice)->get('/friend/requests')->assertOk();
        $content = (string) $response->getContent();

        // _partsManageList.php: a 76×76 photo over the member link, then one operation per cell.
        $response->assertSee('<div class="dparts manageList" id="friend_requests_received">', false);
        $response->assertSee('<div class="item"><table><tbody>', false);
        $response->assertSee('<td class="photo"><a href="'.route('member.profile.show', $bob).'">', false);
        // The sender cannot withdraw a request, so the sent row's operation cell is the empty one.
        $this->assertMatchesRegularExpression('#<td>&nbsp;</td>#', $content);
        // Each box brackets its own list with its own pager.
        $this->assertSame(4, substr_count($content, 'class="pagerRelative"'));
    }

    public function test_manage_page_draws_the_roster_with_the_unlink_column(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)->get('/friend/manage')->assertOk();
        $content = (string) $response->getContent();

        // manageSuccess.php: one manageList parts, the menu's delete class on the operation cell.
        $response->assertSee('id="page_friend_manage"', false);
        $response->assertSee('<div class="dparts manageList" id="manageList">', false);
        $response->assertSee('<td class="photo"><a href="'.route('member.profile.show', $bob).'">', false);
        $response->assertSee('<td class="delete"><a href="'.route('friend.unlink.show', $bob).'">'.e(__('Delete from %my_friend%.')).'</a></td>', false);
        // The pager brackets the roster, as _partsManageList.php draws it.
        $this->assertSame(2, substr_count($content, 'class="pagerRelative"'));
    }

    public function test_an_empty_manage_page_shows_the_warning_box_and_history_back(): void
    {
        $alice = Member::factory()->create();

        $this->actingAs($alice)->get('/friend/manage')
            ->assertOk()
            ->assertSee('id="manageFriendWarning"', false)
            ->assertSee(e(__("You don't have any %friend%.")), false)
            ->assertSee('<a href="'.route('friend.list').'" data-history-back>', false);
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

    public function test_link_show_page_redirects_to_requests_when_request_already_pending(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('friend_requests')->insert([
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);

        $this->actingAs($alice)->get("/friend/link?id={$bob->getKey()}")
            ->assertRedirect(route('friend.requests'));
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

    public function test_unlink_answers_who_it_cannot_unlink_with_a_notice_not_a_404(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();

        foreach (['get', 'post'] as $method) {
            $this->actingAs($alice)->{$method}("/friend/unlink/{$bob->getKey()}")
                ->assertRedirect(route('friend.manage'))
                ->assertSessionHas('error', __('This member is not your %friend%.'));

            $this->actingAs($alice)->{$method}('/friend/unlink/999999')
                ->assertRedirect(route('friend.manage'))
                ->assertSessionHas('error', __('This member is not your %friend%.'));

            $this->actingAs($alice)->{$method}("/friend/unlink/{$alice->getKey()}")
                ->assertRedirect(route('home'));

            $this->actingAs($alice)->{$method}('/friend/unlink/0')
                ->assertRedirect(route('home'));

        }

        // A non-numeric id never matches the route, so it falls to the app-wide unmatched
        // contract: the Classic 404 shell on GET, 405 on any other method.
        $this->actingAs($alice)->get('/friend/unlink/abc')->assertNotFound();
        $this->actingAs($alice)->post('/friend/unlink/abc')->assertStatus(405);
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

        $response->assertRedirect(route('friend.requests'));
        $response->assertSessionHas('error');
    }

    public function test_reject_deletes_request_and_redirects_to_requests(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('friend_requests')->insert([
            'requester_id' => $bob->getKey(),
            'target_id' => $alice->getKey(),
        ]);

        $response = $this->actingAs($alice)->post('/friend/reject', ['requester_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.requests'));
        $response->assertSessionHas('status');
        $this->assertDatabaseCount('friend_requests', 0);
    }

    public function test_unlink_removes_friendship_and_redirects(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)->post("/friend/unlink/{$bob->getKey()}");

        // executeUnlink lands back on the manage roster it came from.
        $response->assertRedirect(route('friend.manage'));
        $response->assertSessionHas('status');
        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_an_empty_list_closes_with_the_history_back_line(): void
    {
        $alice = Member::factory()->create();

        $this->actingAs($alice)->get('/friend/list')
            ->assertOk()
            ->assertSee('id="noFriend"', false)
            ->assertSee('<div class="parts line" id="backLink">', false)
            // A real destination before the script attaches; history.back() once it does.
            ->assertSee('<a href="'.route('home').'" data-history-back>Back to previous page</a>', false)
            ->assertSee(e(asset('js/classic-history-back.js')), false);
    }

    public function test_a_populated_list_has_no_history_back_line(): void
    {
        $alice = Member::factory()->create();
        $this->makeFriends($alice, Member::factory()->create());

        $this->actingAs($alice)->get('/friend/list')
            ->assertOk()
            ->assertDontSee('id="backLink"', false);
    }

    public function test_the_unlink_confirmation_asks_in_its_heading_with_the_member_linked(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $this->makeFriends($alice, $bob);

        $this->actingAs($alice)->get(route('friend.unlink.show', $bob))
            ->assertOk()
            ->assertSee(
                '<h3>Do you delete <a href="'.route('member.profile.show', $bob).'">Bob</a> from my friend?</h3>',
                false
            )
            // unlinkInput.php's no_url: cancel returns to the manage roster.
            ->assertSee('<a href="'.route('friend.manage').'">'.e(__('Cancel')).'</a>', false);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
