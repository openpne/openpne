<?php

namespace Tests\Feature\Friend\Modern;

use App\Models\Member;
use App\Models\MemberImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FriendRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.friend.modern_status' => 'native',
            'openpne.surface_mode' => 'classic_default',
        ]);
    }

    public function test_modern_list_returns_inertia_component_with_serialized_friends(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)->get('/m/friend/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('friend/list')
            ->where('owner.id', $alice->getKey())
            ->where('isOwner', true)
            ->where('friends.meta.total', 1)
            ->where('friends.data.0.name', 'Bob')
        );
    }

    public function test_modern_list_with_id_query_shows_other_owner_friends(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $carol = Member::factory()->create();
        $this->makeFriends($bob, $carol);

        $response = $this->actingAs($alice)->get("/m/friend/list?id={$bob->getKey()}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('friend/list')
            ->where('owner.id', $bob->getKey())
            ->where('isOwner', false)
            ->where('friends.meta.total', 1)
        );
    }

    public function test_modern_list_serializes_the_friend_avatar_url(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        MemberImage::factory()->create(['member_id' => $bob->getKey()]);
        $this->makeFriends($alice, $bob);
        $expected = $bob->load('avatar.file')->avatar->file->thumbnailUrl(76, 76, square: true);

        $this->actingAs($alice)->get('/m/friend/list')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('friends.data.0.id', $bob->getKey())
                ->where('friends.data.0.imageUrl', $expected)
            );
    }

    public function test_modern_list_serializes_a_null_avatar_url_for_a_member_without_one(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        $this->makeFriends($alice, $bob);

        $this->actingAs($alice)->get('/m/friend/list')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('friends.data.0.imageUrl', null));
    }

    public function test_modern_manage_serializes_avatar_urls_for_pending_requesters_and_targets(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        $carol = Member::factory()->create();
        MemberImage::factory()->create(['member_id' => $bob->getKey()]);
        MemberImage::factory()->create(['member_id' => $carol->getKey()]);
        DB::table('friend_requests')->insert([
            ['requester_id' => $bob->getKey(), 'target_id' => $alice->getKey()],
            ['requester_id' => $alice->getKey(), 'target_id' => $carol->getKey()],
        ]);

        $this->actingAs($alice)->get('/m/friend/manage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('received.data.0.imageUrl', $bob->load('avatar.file')->avatar->file->thumbnailUrl(76, 76, square: true))
                ->where('sent.data.0.imageUrl', $carol->load('avatar.file')->avatar->file->thumbnailUrl(76, 76, square: true))
            );
    }

    public function test_modern_manage_returns_inertia_component_with_received_and_sent(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $carol = Member::factory()->create(['name' => 'Carol']);
        DB::table('friend_requests')->insert([
            ['requester_id' => $bob->getKey(), 'target_id' => $alice->getKey()],
            ['requester_id' => $alice->getKey(), 'target_id' => $carol->getKey()],
        ]);

        $response = $this->actingAs($alice)->get('/m/friend/manage');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('friend/manage')
            ->where('received.meta.total', 1)
            ->where('received.data.0.name', 'Bob')
            ->where('sent.meta.total', 1)
            ->where('sent.data.0.name', 'Carol')
        );
    }

    public function test_modern_link_show_returns_inertia_component_with_target(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);

        $response = $this->actingAs($alice)->get("/m/friend/link?id={$bob->getKey()}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('friend/link')
            ->where('target.id', $bob->getKey())
            ->where('target.name', 'Bob')
        );
    }

    public function test_modern_link_show_returns_404_when_target_blocked_viewer(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $bob->getKey(),
            'blocked_id' => $alice->getKey(),
        ]);

        $this->actingAs($alice)->get("/m/friend/link?id={$bob->getKey()}")->assertNotFound();
    }

    public function test_modern_link_show_returns_404_for_self(): void
    {
        $alice = Member::factory()->create();

        $this->actingAs($alice)->get("/m/friend/link?id={$alice->getKey()}")->assertNotFound();
    }

    public function test_modern_link_post_redirects_to_modern_list(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();

        $response = $this->actingAs($alice)
            ->post('/m/friend/link', ['target_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.modern.list'));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('friend_requests', [
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);
    }

    public function test_modern_link_post_redirects_to_modern_list_on_error(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)
            ->post('/m/friend/link', ['target_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.modern.list'));
        $response->assertSessionHas('error');
    }

    public function test_modern_accept_post_redirects_to_modern_list(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('friend_requests')->insert([
            'requester_id' => $bob->getKey(),
            'target_id' => $alice->getKey(),
        ]);

        $response = $this->actingAs($alice)
            ->post('/m/friend/accept', ['requester_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.modern.list'));
        $response->assertSessionHas('status');
    }

    public function test_modern_accept_post_redirects_to_modern_manage_on_error(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();

        $response = $this->actingAs($alice)
            ->post('/m/friend/accept', ['requester_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.modern.manage'));
        $response->assertSessionHas('error');
    }

    public function test_modern_reject_post_redirects_to_modern_manage(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('friend_requests')->insert([
            'requester_id' => $bob->getKey(),
            'target_id' => $alice->getKey(),
        ]);

        $response = $this->actingAs($alice)
            ->post('/m/friend/reject', ['requester_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.modern.manage'));
        $response->assertSessionHas('status');
    }

    public function test_modern_unlink_post_redirects_to_modern_list(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)
            ->post("/m/friend/unlink/{$bob->getKey()}");

        $response->assertRedirect(route('friend.modern.list'));
        $response->assertSessionHas('status');
    }

    public function test_modern_unlink_post_redirects_to_modern_list_on_error(): void
    {
        // No GET confirm page guards non-friends anymore; the POST action redirects with an error.
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();

        $response = $this->actingAs($alice)
            ->post("/m/friend/unlink/{$bob->getKey()}");

        $response->assertRedirect(route('friend.modern.list'));
        $response->assertSessionHas('error');
    }

    public function test_modern_list_paginates_via_page_query(): void
    {
        $alice = Member::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            $friend = Member::factory()->create();
            $this->makeFriends($alice, $friend);
        }

        $response = $this->actingAs($alice)->get('/m/friend/list?page=2');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('friend/list')
            ->where('friends.meta.currentPage', 2)
            ->where('friends.meta.lastPage', 2)
            ->where('friends.meta.total', 25)
        );
    }

    public function test_modern_manage_paginates_received_and_sent_independently(): void
    {
        $alice = Member::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            $requester = Member::factory()->create();
            DB::table('friend_requests')->insert([
                'requester_id' => $requester->getKey(),
                'target_id' => $alice->getKey(),
            ]);
        }

        $response = $this->actingAs($alice)->get('/m/friend/manage?received_page=2');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('friend/manage')
            ->where('received.meta.currentPage', 2)
            ->where('sent.meta.currentPage', 1)
        );
    }

    public function test_modern_submit_error_lands_on_modern_when_session_override_is_modern(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)
            ->withSession(['migration_ui_override' => 'modern'])
            ->post('/friend/link', ['target_id' => $bob->getKey()]);

        $response->assertRedirect(route('friend.list'));
        $response->assertSessionHas('error');

        $followUp = $this->actingAs($alice)
            ->withSession(['migration_ui_override' => 'modern'])
            ->get(route('friend.list'));

        $followUp->assertOk();
        $followUp->assertInertia(fn (AssertableInertia $page) => $page->component('friend/list'));
    }

    public function test_canonical_friend_list_defaults_to_classic(): void
    {
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)->get('/friend/list');

        $response->assertOk();
        $response->assertSee('id="page_friend_list"', false);
    }

    public function test_canonical_friend_list_returns_modern_when_session_override_is_modern(): void
    {
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)
            ->withSession(['migration_ui_override' => 'modern'])
            ->get('/friend/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('friend/list'));
    }

    public function test_canonical_friend_list_returns_classic_when_session_override_is_classic(): void
    {
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)
            ->withSession(['migration_ui_override' => 'classic'])
            ->get('/friend/list');

        $response->assertOk();
        $response->assertSee('id="page_friend_list"', false);
    }

    public function test_canonical_friend_list_returns_modern_when_surface_mode_is_modern_default(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)->get('/friend/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('friend/list'));
    }

    public function test_session_override_is_ignored_when_surface_mode_is_modern_only(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)
            ->withSession(['migration_ui_override' => 'classic'])
            ->get('/friend/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('friend/list'));
    }

    public function test_modern_route_falls_back_to_classic_when_feature_status_is_not_native(): void
    {
        config(['features.friend.modern_status' => 'fallback']);
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)->get('/m/friend/list');

        $response->assertOk();
        $response->assertSee('id="page_friend_list"', false);
    }

    public function test_canonical_route_returns_classic_when_feature_status_is_not_native(): void
    {
        config(['features.friend.modern_status' => 'fallback']);
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)
            ->withSession(['migration_ui_override' => 'modern'])
            ->get('/friend/list');

        $response->assertOk();
        $response->assertSee('id="page_friend_list"', false);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
