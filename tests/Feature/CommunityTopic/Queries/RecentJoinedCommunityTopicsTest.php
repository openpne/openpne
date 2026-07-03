<?php

namespace Tests\Feature\CommunityTopic\Queries;

use App\Features\CommunityTopic\Queries\RecentJoinedCommunityTopics;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentJoinedCommunityTopicsTest extends TestCase
{
    use RefreshDatabase;

    private function join(Member $member, Community $community): void
    {
        CommunityMember::factory()->member()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_returns_topics_only_from_communities_the_member_joined(): void
    {
        $viewer = Member::factory()->create();
        $joined = Community::factory()->create();
        $other = Community::factory()->create();
        $this->join($viewer, $joined);

        $mine = CommunityTopic::factory()->create(['community_id' => $joined->getKey()]);
        CommunityTopic::factory()->create(['community_id' => $other->getKey()]);

        $result = (new RecentJoinedCommunityTopics)($viewer);

        $this->assertCount(1, $result);
        $this->assertSame($mine->getKey(), $result->first()->getKey());
    }

    public function test_orders_by_updated_at_desc_and_caps_at_the_limit(): void
    {
        $viewer = Member::factory()->create();
        $community = Community::factory()->create();
        $this->join($viewer, $community);

        foreach (range(1, 6) as $i) {
            CommunityTopic::factory()->create([
                'community_id' => $community->getKey(),
                'updated_at' => now()->subDays(6 - $i), // i=6 newest
            ]);
        }

        $result = (new RecentJoinedCommunityTopics)($viewer, 3);

        $this->assertCount(3, $result);
        $this->assertTrue($result->first()->updated_at->gt($result->last()->updated_at));
    }

    public function test_loads_the_comment_count(): void
    {
        $viewer = Member::factory()->create();
        $community = Community::factory()->create();
        $this->join($viewer, $community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        CommunityTopicComment::factory()->count(2)->create(['community_topic_id' => $topic->getKey()]);

        $this->assertSame(2, (int) (new RecentJoinedCommunityTopics)($viewer)->first()->comments_count);
    }
}
