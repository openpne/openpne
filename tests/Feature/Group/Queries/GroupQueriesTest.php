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
use Illuminate\Pagination\Paginator;
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

    public function test_search_and_the_member_list_order_groups_by_created_at_then_id(): void
    {
        $member = Member::factory()->create();
        $ids = [];
        foreach (range(1, 25) as $i) {
            $group = Group::factory()->create(['created_at' => '2026-03-01 12:00:00']);
            GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
            $ids[] = $group->getKey();
        }
        $expected = array_reverse($ids);

        $search = fn (int $n) => $this->onPage($n, fn () => (new SearchGroups)(''))->getCollection()->modelKeys();
        $mine = fn (int $n) => $this->onPage($n, fn () => (new ListMemberGroups)($member))->getCollection()->modelKeys();

        $this->assertSame(array_slice($expected, 0, 20), $search(1));
        $this->assertSame(array_slice($expected, 20), $search(2));
        $this->assertSame(array_slice($expected, 0, 20), $mine(1));
        $this->assertSame(array_slice($expected, 20), $mine(2));
    }

    public function test_pending_members_order_by_application_time_then_member_id(): void
    {
        $group = Group::factory()->create();
        $applicants = Member::factory()->count(25)->create();
        DB::table('group_join_requests')->insert($applicants->map(fn (Member $m) => [
            'group_id' => $group->getKey(), 'member_id' => $m->getKey(), 'created_at' => '2026-03-01 12:00:00',
        ])->all());
        $expected = $applicants->modelKeys();

        $page = fn (int $n) => $this->onPage($n, fn () => (new ListPendingMembers)($group))->getCollection()->modelKeys();

        $this->assertSame(array_slice($expected, 0, 20), $page(1));
        $this->assertSame(array_slice($expected, 20), $page(2));
    }

    private function onPage(int $page, callable $run): mixed
    {
        Paginator::currentPageResolver(fn () => $page);
        try {
            return $run();
        } finally {
            Paginator::currentPageResolver(fn () => 1);
        }
    }
}
