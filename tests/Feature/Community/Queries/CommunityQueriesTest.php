<?php

namespace Tests\Feature\Community\Queries;

use App\Features\Community\Queries\ListCommunityMembers;
use App\Features\Community\Queries\ListMemberCommunities;
use App\Features\Community\Queries\ListPendingMembers;
use App\Features\Community\Queries\SearchCommunities;
use App\Features\Community\Queries\ShowCommunity;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityQueriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_community_loads_member_count(): void
    {
        $community = Community::factory()->create();
        CommunityMember::factory()->count(2)->create(['community_id' => $community->getKey()]);

        $found = (new ShowCommunity)($community->getKey());

        $this->assertNotNull($found);
        $this->assertSame(2, $found->members_count);
        $this->assertNull((new ShowCommunity)($community->getKey() + 999));
    }

    public function test_search_filters_by_name_and_category(): void
    {
        $sports = CommunityCategory::factory()->create();
        Community::factory()->create(['name' => 'Tokyo Runners', 'community_category_id' => $sports->getKey()]);
        Community::factory()->create(['name' => 'Osaka Cooks']);

        $byName = (new SearchCommunities)('Runners');
        $this->assertSame(1, $byName->total());
        $this->assertSame('Tokyo Runners', $byName->first()->name);

        $byCategory = (new SearchCommunities)('', $sports->getKey());
        $this->assertSame(1, $byCategory->total());

        $this->assertSame(2, (new SearchCommunities)('')->total());
    }

    public function test_list_member_communities_returns_confirmed_only(): void
    {
        $member = Member::factory()->create();
        $joined = Community::factory()->create();
        CommunityMember::factory()->create(['community_id' => $joined->getKey(), 'member_id' => $member->getKey()]);

        $appliedTo = Community::factory()->approval()->create();
        DB::table('community_join_requests')->insert([
            'community_id' => $appliedTo->getKey(),
            'member_id' => $member->getKey(),
        ]);

        $result = (new ListMemberCommunities)($member);

        $this->assertSame(1, $result->total());
        $this->assertTrue($result->first()->is($joined));
    }

    public function test_list_community_members_orders_admins_first(): void
    {
        $community = Community::factory()->create();
        CommunityMember::factory()->create(['community_id' => $community->getKey()]); // member
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey()]);
        CommunityMember::factory()->subAdmin()->create(['community_id' => $community->getKey()]);

        $members = (new ListCommunityMembers)($community);

        $roles = $members->getCollection()->map(fn (CommunityMember $m): int => $m->role->value)->all();
        $this->assertSame([3, 2, 1], $roles); // Admin, SubAdmin, Member
    }

    public function test_list_pending_members_returns_applicants(): void
    {
        $community = Community::factory()->approval()->create();
        $applicant = Member::factory()->create();
        DB::table('community_join_requests')->insert([
            'community_id' => $community->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $pending = (new ListPendingMembers)($community);

        $this->assertSame(1, $pending->total());
        $this->assertTrue($pending->first()->is($applicant));
    }
}
