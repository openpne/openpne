<?php

namespace Tests\Feature\Classic;

use App\Models\Gadget;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Services\GadgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Classic photo table labels every row "name (friend count)", which is the shape that invites an
 * N+1. The counts are subqueries on the paged query instead, so a page of 12 costs what a page of 3
 * does. The gadget grids share the same list queries and print one number — the whole-set total
 * behind "show all (n)" — so their unpaged path buys it with a single aggregate and keeps the
 * per-row count subquery out.
 */
class ClassicPhotoTableRowCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_friend_grid_costs_the_same_at_three_rows_and_twelve(): void
    {
        $small = $this->memberWithFriends(3);
        $large = $this->memberWithFriends(12);

        $this->assertSame(
            $this->queryCountFor($small, '/friend/list'),
            $this->queryCountFor($large, '/friend/list'),
        );
    }

    public function test_the_community_member_grid_costs_the_same_at_three_rows_and_twelve(): void
    {
        $viewer = Member::factory()->create();
        $small = $this->groupWithMembers($viewer, 3);
        $large = $this->groupWithMembers($viewer, 12);

        $this->assertSame(
            $this->queryCountFor($viewer, "/groups/{$small->getKey()}/members"),
            $this->queryCountFor($viewer, "/groups/{$large->getKey()}/members"),
        );
    }

    public function test_the_friend_list_gadget_totals_with_one_aggregate_and_no_per_row_count(): void
    {
        Gadget::create(['context' => 'home', 'zone' => 'sideMenu', 'name' => 'friendListBox', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();

        $small = $this->memberWithFriends(3);
        $large = $this->memberWithFriends(12);

        $this->assertSame($this->queryCountFor($small, '/'), $this->queryCountFor($large, '/'));

        $queries = $this->queriesFor($large, '/');

        // "Show all (n)" is the whole set, which the grid slice cannot report — but one aggregate
        // answers it, and take() must still not pick up the paged path's per-row withCount, whose
        // alias is the marker that the subquery ran.
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('friendships_count', $query);
        }
        $this->assertSame(1, $this->aggregateCountsOver($queries, 'friendships'));
    }

    public function test_the_community_list_gadget_totals_with_one_aggregate_and_crowns_in_the_slice(): void
    {
        Gadget::create(['context' => 'home', 'zone' => 'sideMenu', 'name' => 'groupJoinListBox', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();

        $small = $this->memberInGroups(3);
        $large = $this->memberInGroups(12);

        $this->assertSame($this->queryCountFor($small, '/'), $this->queryCountFor($large, '/'));

        // The crown flag is a correlated exists in the slice's select list, not a query per row, so
        // the aggregate for the total stays the gadget's only extra round trip.
        $this->assertSame(1, $this->aggregateCountsOver($this->queriesFor($large, '/'), 'groups'));
    }

    private function memberWithFriends(int $count): Member
    {
        $member = Member::factory()->create();
        foreach (Member::factory()->count($count)->create() as $friend) {
            DB::table('friendships')->insert([
                ['member_id' => $member->getKey(), 'friend_id' => $friend->getKey()],
                ['member_id' => $friend->getKey(), 'friend_id' => $member->getKey()],
            ]);
        }

        return $member;
    }

    private function memberInGroups(int $count): Member
    {
        $member = Member::factory()->create();
        foreach (Group::factory()->count($count)->create() as $group) {
            GroupMember::factory()->create([
                'group_id' => $group->getKey(),
                'member_id' => $member->getKey(),
            ]);
        }

        return $member;
    }

    /**
     * How many of $queries are a standalone COUNT over $table. Anchored on the aggregate select so
     * a `count(*)` nested in a list query's select list is not mistaken for a round trip.
     *
     * @param  list<string>  $queries
     */
    private function aggregateCountsOver(array $queries, string $table): int
    {
        return count(array_filter(
            $queries,
            fn (string $query) => str_starts_with($query, 'select count(*) as ')
                && str_contains($query, $table),
        ));
    }

    private function groupWithMembers(Member $viewer, int $count): Group
    {
        $group = Group::factory()->create();
        GroupMember::factory()->admin()->create([
            'group_id' => $group->getKey(),
            'member_id' => $viewer->getKey(),
        ]);
        foreach (Member::factory()->count($count - 1)->create() as $member) {
            GroupMember::factory()->create([
                'group_id' => $group->getKey(),
                'member_id' => $member->getKey(),
            ]);
        }

        return $group;
    }

    /** @return list<string> */
    private function queriesFor(Member $viewer, string $uri): array
    {
        $this->actingAs($viewer)->get($uri)->assertOk(); // warm the process-wide caches first
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get($uri)->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    private function queryCountFor(Member $viewer, string $uri): int
    {
        return count($this->queriesFor($viewer, $uri));
    }
}
