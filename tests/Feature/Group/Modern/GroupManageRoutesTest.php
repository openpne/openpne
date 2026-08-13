<?php

namespace Tests\Feature\Group\Modern;

use App\Features\Group\GroupRole;
use App\Features\Group\Events\AdminTransferRequested;
use App\Features\Group\Events\SubAdminAppointed;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GroupManageRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_manage_renders_roster_with_roles_viewer_role_and_pending_admin(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $member = $this->join($group, GroupRole::Member);

        $this->actingAs($admin)->get("/groups/{$group->getKey()}/members/manage")
            ->assertInertia(fn ($page) => $page
                ->component('community/manage')
                ->where('viewerRole', 'admin')
                ->where('pendingAdminId', null)
                ->has('members.data', 2)
                ->where('members.data.0.role', 'admin') // admins first
                ->where('members.data.1.id', $member->getKey())
                ->where('members.data.1.role', 'member'));
    }

    public function test_manage_exposes_pending_admin_id_to_the_roster(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $member = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $member->getKey()])->save();

        $this->actingAs($admin)->get("/groups/{$group->getKey()}/members/manage")
            ->assertInertia(fn ($page) => $page->where('pendingAdminId', $member->getKey()));
    }

    public function test_manage_returns_404_for_a_non_manager(): void
    {
        $stranger = Member::factory()->create();
        $group = Group::factory()->create();

        $this->actingAs($stranger)->get("/groups/{$group->getKey()}/members/manage")->assertNotFound();
    }

    public function test_confirm_gets_redirect_to_manage_after_guards_pass(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);
        $manage = route('group.members.manage', $group);

        $this->actingAs($admin)->get($this->confirmUrl('appoint', $group, $member))->assertRedirect($manage);
        $this->actingAs($admin)->get($this->confirmUrl('demote', $group, $sub))->assertRedirect($manage);
        $this->actingAs($admin)->get($this->confirmUrl('drop', $group, $member))->assertRedirect($manage);
    }

    public function test_invalid_target_confirm_gets_still_404_under_modern(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);

        // A guard failure 404s before the Modern redirect can fire.
        $this->actingAs($admin)->get($this->confirmUrl('appoint', $group, $sub))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('demote', $group, $member))->assertNotFound();
        $this->actingAs($admin)->get($this->confirmUrl('drop', $group, $admin))->assertNotFound();
    }

    public function test_posts_mutate_and_redirect_to_manage(): void
    {
        Event::fake([SubAdminAppointed::class]);
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $member = $this->join($group, GroupRole::Member);
        $manage = route('group.members.manage', $group);

        $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/appoint', ['member_id' => $member->getKey()])
            ->assertRedirect($manage);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => GroupRole::SubAdmin->value]);

        $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/demote', ['member_id' => $member->getKey()])
            ->assertRedirect($manage);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => GroupRole::Member->value]);

        $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/drop', ['member_id' => $member->getKey()])
            ->assertRedirect($manage);
        $this->assertDatabaseMissing('group_members', ['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
    }

    public function test_show_exposes_the_manage_affordance_to_managers_only(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $sub = $this->join($group, GroupRole::SubAdmin);
        $member = $this->join($group, GroupRole::Member);

        // The manage link in community/show is gated on canManage; viewerRole distinguishes admin
        // (also sees Pending members) from sub-admin.
        $this->actingAs($admin)->get(route('group.show', $group))
            ->assertInertia(fn ($page) => $page->component('community/show')->where('canManage', true)->where('viewerRole', 'admin'));
        $this->actingAs($sub)->get(route('group.show', $group))
            ->assertInertia(fn ($page) => $page->where('canManage', true)->where('viewerRole', 'sub_admin'));
        $this->actingAs($member)->get(route('group.show', $group))
            ->assertInertia(fn ($page) => $page->where('canManage', false));
    }

    public function test_transfer_confirm_redirects_to_manage_for_a_valid_target_and_404s_for_invalid(): void
    {
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $member = $this->join($group, GroupRole::Member);
        $manage = route('group.members.manage', $group);

        $this->actingAs($admin)->get($this->confirmUrl('transfer', $group, $member))->assertRedirect($manage);
        // The admin (self) target is invalid → 404 before the Modern redirect can fire.
        $this->actingAs($admin)->get($this->confirmUrl('transfer', $group, $admin))->assertNotFound();
    }

    public function test_transfer_request_and_accept_mutate_and_redirect(): void
    {
        Event::fake([AdminTransferRequested::class]);
        $group = Group::factory()->create();
        $admin = $this->join($group, GroupRole::Admin);
        $nominee = $this->join($group, GroupRole::Member);

        $this->actingAs($admin)->post('/groups/'.$group->getKey().'/members/transfer', ['member_id' => $nominee->getKey()])
            ->assertRedirect(route('group.members.manage', $group));
        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'pending_admin_member_id' => $nominee->getKey()]);

        $this->actingAs($nominee)->post('/groups/'.$group->getKey().'/members/transfer/accept')
            ->assertRedirect(route('group.show', $group));
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $nominee->getKey(), 'role' => GroupRole::Admin->value]);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $admin->getKey(), 'role' => GroupRole::Member->value]);
    }

    public function test_transfer_reject_clears_pending(): void
    {
        $group = Group::factory()->create();
        $this->join($group, GroupRole::Admin);
        $nominee = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $this->actingAs($nominee)->post('/groups/'.$group->getKey().'/members/transfer/reject')
            ->assertRedirect(route('group.show', $group));
        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'pending_admin_member_id' => null]);
    }

    public function test_show_exposes_is_transfer_nominee(): void
    {
        $group = Group::factory()->create();
        $this->join($group, GroupRole::Admin);
        $nominee = $this->join($group, GroupRole::Member);
        $other = $this->join($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $this->actingAs($nominee)->get(route('group.show', $group))
            ->assertInertia(fn ($page) => $page->component('community/show')->where('isTransferNominee', true));
        $this->actingAs($other)->get(route('group.show', $group))
            ->assertInertia(fn ($page) => $page->where('isTransferNominee', false));
    }

    private function join(Group $group, GroupRole $role): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    private function confirmUrl(string $path, Group $group, Member $member): string
    {
        return "/groups/{$group->getKey()}/members/{$path}?member_id={$member->getKey()}";
    }
}
