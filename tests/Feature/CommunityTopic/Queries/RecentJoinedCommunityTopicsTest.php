<?php

namespace Tests\Feature\CommunityTopic\Queries;

use App\Features\CommunityTopic\Queries\RecentJoinedCommunityTopics;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentJoinedCommunityTopicsTest extends TestCase
{
    use RefreshDatabase;

    private function join(Member $member, Group $group): void
    {
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_returns_topics_only_from_communities_the_member_joined(): void
    {
        $viewer = Member::factory()->create();
        $joined = Group::factory()->create();
        $other = Group::factory()->create();
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
        $group = Group::factory()->create();
        $this->join($viewer, $group);

        foreach (range(1, 6) as $i) {
            CommunityTopic::factory()->create([
                'community_id' => $group->getKey(),
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
        $group = Group::factory()->create();
        $this->join($viewer, $group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey()]);
        CommunityTopicComment::factory()->count(2)->create(['community_topic_id' => $topic->getKey()]);

        $this->assertSame(2, (int) (new RecentJoinedCommunityTopics)($viewer)->first()->comments_count);
    }
}
