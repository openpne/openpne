<?php

namespace Tests\Feature\Group;

use App\Features\Group\GroupRole;
use App\Features\Group\JoinPolicy;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_policy_and_role_cast_to_enums(): void
    {
        $group = Group::factory()->approval()->create();
        $member = GroupMember::factory()->admin()->create(['group_id' => $group->getKey()]);

        $this->assertSame(JoinPolicy::Approval, $group->refresh()->register_policy);
        $this->assertSame(GroupRole::Admin, $member->refresh()->role);
    }

    public function test_pending_join_requests_live_in_their_own_table(): void
    {
        $group = Group::factory()->approval()->create();
        $applicant = Member::factory()->create();
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        // An applicant is reachable as a pending applicant, never as a confirmed member.
        $this->assertTrue($group->applicants->first()->is($applicant));
        $this->assertSame(0, $group->members()->count());
        $this->assertTrue($applicant->groupJoinRequests->first()->is($group));
        $this->assertCount(0, $applicant->groupMemberships);
    }

    public function test_relations_resolve(): void
    {
        $category = GroupCategory::factory()->create();
        $group = Group::factory()->create(['group_category_id' => $category->getKey()]);
        $member = Member::factory()->create();
        $membership = GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);

        $this->assertTrue($group->category->is($category));
        $this->assertTrue($group->members->first()->is($membership));
        $this->assertTrue($membership->group->is($group));
        $this->assertTrue($membership->member->is($member));
        $this->assertTrue($member->groupMemberships->first()->is($membership));
        $this->assertTrue($category->groups->first()->is($group));
    }

    public function test_community_name_is_unique(): void
    {
        Group::factory()->create(['name' => 'Hiking Club']);

        $this->expectException(QueryException::class);
        Group::factory()->create(['name' => 'Hiking Club']);
    }

    public function test_membership_is_unique_per_community_and_member(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);

        $this->expectException(QueryException::class);
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_deleting_a_community_cascades_to_memberships(): void
    {
        $membership = GroupMember::factory()->create();
        $groupId = $membership->group_id;

        $membership->group->delete();

        $this->assertDatabaseMissing('group_members', ['group_id' => $groupId]);
    }
}
