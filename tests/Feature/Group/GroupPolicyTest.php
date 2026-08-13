<?php

namespace Tests\Feature\Group;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class GroupPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_is_allowed_for_admin_and_sub_admin_only(): void
    {
        $group = Group::factory()->create();
        $admin = $this->memberWithRole($group, 'admin');
        $sub = $this->memberWithRole($group, 'subAdmin');
        $member = $this->memberWithRole($group, 'member');
        $stranger = Member::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('update', $group));
        $this->assertTrue(Gate::forUser($sub)->allows('update', $group));
        $this->assertFalse(Gate::forUser($member)->allows('update', $group));
        $this->assertFalse(Gate::forUser($stranger)->allows('update', $group));
    }

    public function test_delete_and_manage_members_are_admin_only(): void
    {
        $group = Group::factory()->create();
        $admin = $this->memberWithRole($group, 'admin');
        $sub = $this->memberWithRole($group, 'subAdmin');

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $group));
        $this->assertTrue(Gate::forUser($admin)->allows('manageMembers', $group));
        $this->assertFalse(Gate::forUser($sub)->allows('delete', $group));
        $this->assertFalse(Gate::forUser($sub)->allows('manageMembers', $group));
    }

    public function test_moderate_members_is_allowed_for_admin_and_sub_admin_only(): void
    {
        $group = Group::factory()->create();
        $admin = $this->memberWithRole($group, 'admin');
        $sub = $this->memberWithRole($group, 'subAdmin');
        $member = $this->memberWithRole($group, 'member');
        $stranger = Member::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('moderateMembers', $group));
        $this->assertTrue(Gate::forUser($sub)->allows('moderateMembers', $group));
        $this->assertFalse(Gate::forUser($member)->allows('moderateMembers', $group));
        $this->assertFalse(Gate::forUser($stranger)->allows('moderateMembers', $group));
    }

    public function test_view_is_allowed_for_any_member(): void
    {
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();

        $this->assertTrue(Gate::forUser($stranger)->allows('view', $group));
    }

    private function memberWithRole(Group $group, string $role): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->{$role}()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);

        return $member;
    }
}
