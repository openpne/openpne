<?php

namespace Tests\Feature\Friend\Modern;

use App\Models\Member;
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
            'openpne.tenant_mode' => 'mixed',
            'openpne.tenant_default_surface' => 'classic',
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

    public function test_modern_unlink_show_returns_inertia_component(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create(['name' => 'Bob']);
        $this->makeFriends($alice, $bob);

        $response = $this->actingAs($alice)->get("/m/friend/unlink/{$bob->getKey()}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('friend/unlink')
            ->where('target.id', $bob->getKey())
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

    public function test_modern_unlink_show_returns_404_when_not_friends(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();

        $this->actingAs($alice)->get("/m/friend/unlink/{$bob->getKey()}")->assertNotFound();
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

    public function test_canonical_friend_list_returns_modern_when_tenant_default_is_modern(): void
    {
        config(['openpne.tenant_default_surface' => 'modern']);
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)->get('/friend/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('friend/list'));
    }

    public function test_session_override_is_ignored_when_tenant_mode_is_modern_only(): void
    {
        config(['openpne.tenant_mode' => 'modern_only']);
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)
            ->withSession(['migration_ui_override' => 'classic'])
            ->get('/friend/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('friend/list'));
    }

    public function test_modern_route_returns_modern_even_when_feature_status_is_not_native(): void
    {
        config(['features.friend.modern_status' => 'fallback']);
        $alice = Member::factory()->create();

        $response = $this->actingAs($alice)->get('/m/friend/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('friend/list'));
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
