<?php

namespace Tests\Feature\Group;

use App\Features\Group\GroupMembership;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_of_returns_role_for_members_and_null_for_strangers(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create();
        $stranger = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->assertSame(GroupRole::Admin, GroupMembership::roleOf($group, $admin));
        $this->assertNull(GroupMembership::roleOf($group, $stranger));
        $this->assertTrue(GroupMembership::isMember($group, $admin));
        $this->assertFalse(GroupMembership::isMember($group, $stranger));
        $this->assertTrue(GroupMembership::isAdmin($group, $admin));
        $this->assertTrue(GroupMembership::canManage($group, $admin));
    }

    public function test_sub_admin_can_manage_but_is_not_admin(): void
    {
        $group = Group::factory()->create();
        $sub = Member::factory()->create();
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->getKey(), 'member_id' => $sub->getKey()]);

        $this->assertTrue(GroupMembership::canManage($group, $sub));
        $this->assertFalse(GroupMembership::isAdmin($group, $sub));
    }

    public function test_plain_member_cannot_manage(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $this->assertFalse(GroupMembership::canManage($group, $member));
    }

    public function test_is_pending_reads_the_join_request_table(): void
    {
        $group = Group::factory()->create();
        $applicant = Member::factory()->create();

        $this->assertFalse(GroupMembership::isPending($group, $applicant));

        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $this->assertTrue(GroupMembership::isPending($group, $applicant));
        $this->assertFalse(GroupMembership::isMember($group, $applicant));
    }
}
