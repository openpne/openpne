<?php

namespace Tests\Feature\Group\Modern;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\MemberImage;
use App\Support\AvatarColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_modern_search_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/groups')
            ->assertInertia(fn ($page) => $page->component('community/search')->has('groups.data'));
    }

    public function test_modern_joined_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/groups/mine')
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
        $expected = $member->load('avatar.file')->avatar->file->thumbnailUrl(120, 120, square: true);

        $this->actingAs($member)
            ->get('/groups/mine')
            ->assertInertia(fn ($page) => $page
                ->where('owner.imageUrl', $expected)
                ->where('owner.avatarColor', '#15803d')
            );
    }

    public function test_modern_show_renders_inertia_component_with_community_props(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create();

        $this->actingAs($member)
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/show')
                ->where('group.id', $group->getKey())
                ->has('group.name')
                ->where('group.registerPolicy', 'open')
                ->where('canJoin', true)
                ->where('viewerRole', null)
            );
    }

    public function test_modern_show_names_the_staff_the_join_policy_and_the_opening_day(): void
    {
        $viewer = Member::factory()->create();
        $admin = Member::factory()->create(['name' => 'Ada Admin']);
        $sub = Member::factory()->create(['name' => 'Sam Sub']);
        $group = Group::factory()->create(['created_at' => '2019-04-05 12:00:00']);
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->getKey(), 'member_id' => $sub->getKey()]);
        GroupMember::factory()->member()->create(['group_id' => $group->getKey()]);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('about.createdOn', '2019-04-05')
                ->where('about.admin', ['id' => $admin->getKey(), 'name' => 'Ada Admin'])
                ->where('about.subAdmins', [['id' => $sub->getKey(), 'name' => 'Sam Sub']])
                ->where('about.registerPolicy', 'Anyone can join')
            );
    }

    public function test_modern_show_recent_topics_carry_the_author_byline(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
        GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/show')
                ->where('recentTopics.0.author.name', $member->name)
            );
    }

    public function test_modern_show_returns_404_for_missing_community(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/groups/999999')->assertNotFound();
    }

    public function test_modern_members_serializes_role_as_string_slug(): void
    {
        $admin = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->admin()->create([
            'group_id' => $group->getKey(),
            'member_id' => $admin->getKey(),
        ]);

        $this->actingAs($admin)
            ->get("/groups/{$group->getKey()}/members")
            ->assertInertia(fn ($page) => $page
                ->component('community/members')
                ->where('members.data.0.role', 'admin')
                ->where('members.data.0.id', $admin->getKey())
            );
    }

    public function test_modern_join_creates_membership_and_redirects_to_show(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create(); // Open policy

        $this->actingAs($member)
            ->post('/groups/'.$group->getKey().'/join')
            ->assertRedirect(route('group.show', $group));

        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_quit_removes_membership_and_redirects_to_show(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create();
        // Keep an admin so the community is not left admin-less; the member leaves as a plain member.
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey()]);
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);

        $this->actingAs($member)
            ->post('/groups/'.$group->getKey().'/quit')
            ->assertRedirect(route('group.show', $group));

        $this->assertDatabaseMissing('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_only_serves_inertia_on_the_canonical_show_route(): void
    {
        // modern_only (as opposed to the suite's modern_default pin) also resolves the canonical
        // community route to Modern.
        config()->set('openpne.surface_mode', 'modern_only');
        $member = Member::factory()->create();
        $group = Group::factory()->create();

        $this->actingAs($member)
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->component('community/show'));
    }
}
