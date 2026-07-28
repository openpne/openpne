<?php

namespace Tests\Feature\Classic;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Gadget;
use App\Models\Member;
use App\Services\GadgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Classic photo table labels every row "name (friend count)", which is the shape that invites an
 * N+1. The counts are subqueries on the paged query instead, so a page of 12 costs what a page of 3
 * does. The gadget grids share the same list queries but print no count, so their unpaged path must
 * stay free of the count subquery entirely.
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
        $small = $this->communityWithMembers($viewer, 3);
        $large = $this->communityWithMembers($viewer, 12);

        $this->assertSame(
            $this->queryCountFor($viewer, "/community/member/list?id={$small->getKey()}"),
            $this->queryCountFor($viewer, "/community/member/list?id={$large->getKey()}"),
        );
    }

    public function test_the_friend_list_gadget_neither_counts_nor_grows(): void
    {
        Gadget::create(['context' => 'home', 'zone' => 'sideMenu', 'name' => 'friendListBox', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();

        $small = $this->memberWithFriends(3);
        $large = $this->memberWithFriends(12);

        $this->assertSame($this->queryCountFor($small, '/'), $this->queryCountFor($large, '/'));

        // take() must not pick up the paged path's withCount: the gadget prints bare names, and the
        // alias is the marker that the subquery ran.
        foreach ($this->queriesFor($large, '/') as $query) {
            $this->assertStringNotContainsString('friendships_count', $query);
        }
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

    private function communityWithMembers(Member $viewer, int $count): Community
    {
        $community = Community::factory()->create();
        CommunityMember::factory()->admin()->create([
            'community_id' => $community->getKey(),
            'member_id' => $viewer->getKey(),
        ]);
        foreach (Member::factory()->count($count - 1)->create() as $member) {
            CommunityMember::factory()->create([
                'community_id' => $community->getKey(),
                'member_id' => $member->getKey(),
            ]);
        }

        return $community;
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
