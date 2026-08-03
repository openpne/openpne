<?php

namespace Tests\Feature\Community\Modern;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Member;
use App\Models\MemberImage;
use App\Support\AvatarColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $community = Community::factory()->create();

        $this->get('/community/search')->assertRedirect('/login');
        $this->get('/community/joinList')->assertRedirect('/login');
        $this->get("/community/{$community->getKey()}")->assertRedirect('/login');
        $this->get("/community/member/list?id={$community->getKey()}")->assertRedirect('/login');
        $this->post('/community/join', ['id' => $community->getKey()])->assertRedirect('/login');
    }

    public function test_modern_search_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/community/search')
            ->assertInertia(fn ($page) => $page->component('community/search')->has('communities.data'));
    }

    public function test_modern_joined_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/community/joinList')
            ->assertInertia(fn ($page) => $page
                ->component('community/list')
                ->where('isOwner', true)
                ->where('owner.id', $member->getKey())
            );
    }

    public function test_the_joined_list_owner_ref_carries_the_avatar_the_chrome_scope_draws(): void
    {
        $member = Member::factory()->create();
        $member->forceFill(['avatar_color' => AvatarColor::Green])->save();
        MemberImage::factory()->create(['member_id' => $member->getKey()]);
        $expected = $member->load('avatar.file')->avatar->file->thumbnailUrl(76, 76, square: true);

        $this->actingAs($member)
            ->get('/community/joinList')
            ->assertInertia(fn ($page) => $page
                ->where('owner.imageUrl', $expected)
                ->where('owner.avatarColor', '#15803d')
            );
    }

    public function test_modern_show_renders_inertia_component_with_community_props(): void
    {
        $member = Member::factory()->create();
        $community = Community::factory()->create();

        $this->actingAs($member)
            ->get("/community/{$community->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/show')
                ->where('community.id', $community->getKey())
                ->has('community.name')
                ->where('community.registerPolicy', 'open')
                ->where('canJoin', true)
                ->where('viewerRole', null)
            );
    }

    public function test_modern_show_recent_topics_carry_the_author_byline(): void
    {
        $member = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
        CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/community/{$community->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/show')
                ->where('recentTopics.0.author.name', $member->name)
            );
    }

    public function test_modern_show_returns_404_for_missing_community(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/community/999999')->assertNotFound();
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
            ->get("/community/member/list?id={$community->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/members')
                ->where('members.data.0.role', 'admin')
                ->where('members.data.0.id', $admin->getKey())
            );
    }

    public function test_modern_join_creates_membership_and_redirects_to_show(): void
    {
        $member = Member::factory()->create();
        $community = Community::factory()->create(); // Open policy

        $this->actingAs($member)
            ->post('/community/join', ['id' => $community->getKey()])
            ->assertRedirect(route('community.show', $community));

        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_quit_removes_membership_and_redirects_to_show(): void
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
            ->post('/community/quit', ['id' => $community->getKey()])
            ->assertRedirect(route('community.show', $community));

        $this->assertDatabaseMissing('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_only_serves_inertia_on_the_canonical_show_route(): void
    {
        // modern_only (as opposed to the suite's modern_default pin) also resolves the canonical
        // community route to Modern.
        config()->set('openpne.surface_mode', 'modern_only');
        $member = Member::factory()->create();
        $community = Community::factory()->create();

        $this->actingAs($member)
            ->get("/community/{$community->getKey()}")
            ->assertInertia(fn ($page) => $page->component('community/show'));
    }
}
