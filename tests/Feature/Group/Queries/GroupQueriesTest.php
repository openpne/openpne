<?php

namespace Tests\Feature\Group\Queries;

use App\Features\Group\Queries\ListGroupMembers;
use App\Features\Group\Queries\ListMemberGroups;
use App\Features\Group\Queries\ListPendingMembers;
use App\Features\Group\Queries\SearchGroups;
use App\Features\Group\Queries\ShowGroup;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupQueriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_community_loads_member_count(): void
    {
        $group = Group::factory()->create();
        GroupMember::factory()->count(2)->create(['group_id' => $group->getKey()]);

        $found = (new ShowGroup)($group->getKey());

        $this->assertNotNull($found);
        $this->assertSame(2, $found->members_count);
        $this->assertNull((new ShowGroup)($group->getKey() + 999));
    }

    public function test_search_filters_by_name_and_category(): void
    {
        $sports = GroupCategory::factory()->create();
        Group::factory()->create(['name' => 'Tokyo Runners', 'group_category_id' => $sports->getKey()]);
        Group::factory()->create(['name' => 'Osaka Cooks']);

        $byName = (new SearchGroups)('Runners');
        $this->assertSame(1, $byName->total());
        $this->assertSame('Tokyo Runners', $byName->first()->name);

        $byCategory = (new SearchGroups)('', $sports->getKey());
        $this->assertSame(1, $byCategory->total());

        $this->assertSame(2, (new SearchGroups)('')->total());
    }

    public function test_list_member_communities_returns_confirmed_only(): void
    {
        $member = Member::factory()->create();
        $joined = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $joined->getKey(), 'member_id' => $member->getKey()]);

        $appliedTo = Group::factory()->approval()->create();
        DB::table('group_join_requests')->insert([
            'group_id' => $appliedTo->getKey(),
            'member_id' => $member->getKey(),
        ]);

        $result = (new ListMemberGroups)($member);

        $this->assertSame(1, $result->total());
        $this->assertTrue($result->first()->is($joined));
    }

    public function test_list_community_members_orders_admins_first(): void
    {
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey()]); // member
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey()]);
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->getKey()]);

        $members = (new ListGroupMembers)($group);

        $roles = $members->getCollection()->map(fn (GroupMember $m): int => $m->role->value)->all();
        $this->assertSame([3, 2, 1], $roles); // Admin, SubAdmin, Member
    }

    public function test_list_pending_members_returns_applicants(): void
    {
        $group = Group::factory()->approval()->create();
        $applicant = Member::factory()->create();
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $pending = (new ListPendingMembers)($group);

        $this->assertSame(1, $pending->total());
        $this->assertTrue($pending->first()->is($applicant));
    }
}
