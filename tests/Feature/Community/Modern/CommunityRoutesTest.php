<?php

namespace Tests\Feature\Community\Modern;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_for_modern_routes(): void
    {
        $community = Community::factory()->create();

        $this->get('/m/community/search')->assertRedirect('/login');
        $this->get('/m/community/joined')->assertRedirect('/login');
        $this->get("/m/community/{$community->getKey()}")->assertRedirect('/login');
        $this->get("/m/community/{$community->getKey()}/members")->assertRedirect('/login');
        $this->post("/m/community/{$community->getKey()}/join")->assertRedirect('/login');
    }

    public function test_modern_search_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/m/community/search')
            ->assertInertia(fn ($page) => $page->component('community/search')->has('communities.data'));
    }

    public function test_modern_joined_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/m/community/joined')
            ->assertInertia(fn ($page) => $page
                ->component('community/list')
                ->where('isOwner', true)
                ->where('owner.id', $member->getKey())
            );
    }

    public function test_modern_show_renders_inertia_component_with_community_props(): void
    {
        $member = Member::factory()->create();
        $community = Community::factory()->create();

        $this->actingAs($member)
            ->get("/m/community/{$community->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/show')
                ->where('community.id', $community->getKey())
                ->has('community.name')
                ->where('community.registerPolicy', 'open')
                ->where('canJoin', true)
                ->where('viewerRole', null)
            );
    }

    public function test_modern_show_returns_404_for_missing_community(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/community/999999')->assertNotFound();
    }

    public function test_modern_members_serializes_role_as_string_slug(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->admin()->create([
            'community_id' => $community->getKey(),
            'member_id' => $admin->getKey(),
        ]);

        $this->actingAs($admin)
            ->get("/m/community/{$community->getKey()}/members")
            ->assertInertia(fn ($page) => $page
                ->component('community/members')
                ->where('members.data.0.role', 'admin')
                ->where('members.data.0.id', $admin->getKey())
            );
    }

    public function test_modern_join_creates_membership_and_redirects_to_modern_show(): void
    {
        $member = Member::factory()->create();
        $community = Community::factory()->create(); // Open policy

        $this->actingAs($member)
            ->post("/m/community/{$community->getKey()}/join")
            ->assertRedirect(route('community.modern.show', $community));

        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_quit_removes_membership_and_redirects_to_modern_show(): void
    {
        $member = Member::factory()->create();
        $community = Community::factory()->create();
        // Keep an admin so the community is not left admin-less; the member leaves as a plain member.
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey()]);
        CommunityMember::factory()->member()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);

        $this->actingAs($member)
            ->post("/m/community/{$community->getKey()}/quit")
            ->assertRedirect(route('community.modern.show', $community));

        $this->assertDatabaseMissing('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_only_serves_inertia_on_the_canonical_show_route(): void
    {
        // With no /m opt-in, modern_only still resolves the canonical community route to Modern.
        config()->set('openpne.tenant_mode', 'modern_only');
        $member = Member::factory()->create();
        $community = Community::factory()->create();

        $this->actingAs($member)
            ->get("/community/{$community->getKey()}")
            ->assertInertia(fn ($page) => $page->component('community/show'));
    }
}
