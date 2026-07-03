<?php

namespace Tests\Feature\Community\Queries;

use App\Features\Community\Queries\RandomJoinedCommunities;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RandomJoinedCommunitiesTest extends TestCase
{
    use RefreshDatabase;

    private function join(Member $member, Community $community): void
    {
        CommunityMember::factory()->member()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_returns_only_communities_the_viewer_joined(): void
    {
        $viewer = Member::factory()->create();
        $joined = Community::factory()->create();
        Community::factory()->create(); // not joined
        $this->join($viewer, $joined);

        $result = (new RandomJoinedCommunities)($viewer);

        $this->assertCount(1, $result);
        $this->assertSame($joined->getKey(), $result->first()->getKey());
    }

    public function test_caps_at_the_limit(): void
    {
        $viewer = Member::factory()->create();
        foreach (Community::factory()->count(12)->create() as $community) {
            $this->join($viewer, $community);
        }

        $this->assertCount(9, (new RandomJoinedCommunities)($viewer));
        $this->assertCount(3, (new RandomJoinedCommunities)($viewer, 3));
    }

    public function test_is_empty_without_memberships(): void
    {
        $this->assertCount(0, (new RandomJoinedCommunities)(Member::factory()->create()));
    }
}
