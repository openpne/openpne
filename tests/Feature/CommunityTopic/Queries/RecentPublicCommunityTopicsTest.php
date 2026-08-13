<?php

namespace Tests\Feature\CommunityTopic\Queries;

use App\Features\CommunityTopic\Queries\RecentPublicCommunityTopics;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentPublicCommunityTopicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_topics_only_from_public_communities(): void
    {
        $public = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $membersOnly = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);

        $shown = CommunityTopic::factory()->create(['community_id' => $public->getKey()]);
        CommunityTopic::factory()->create(['community_id' => $membersOnly->getKey()]);

        $result = (new RecentPublicCommunityTopics)();

        $this->assertCount(1, $result);
        $this->assertSame($shown->getKey(), $result->first()->getKey());
    }

    public function test_orders_by_updated_at_desc_and_caps_at_the_limit(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        foreach (range(1, 6) as $i) {
            CommunityTopic::factory()->create([
                'community_id' => $group->getKey(),
                'updated_at' => now()->subDays(6 - $i), // i=6 newest
            ]);
        }

        $result = (new RecentPublicCommunityTopics)(3);

        $this->assertCount(3, $result);
        $this->assertTrue($result->first()->updated_at->gt($result->last()->updated_at));
    }

    public function test_loads_the_comment_count(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey()]);
        CommunityTopicComment::factory()->count(2)->create(['community_topic_id' => $topic->getKey()]);

        $this->assertSame(2, (int) (new RecentPublicCommunityTopics)()->first()->comments_count);
    }

    public function test_is_viewer_independent(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        CommunityTopic::factory()->count(2)->create(['community_id' => $group->getKey()]);

        // No viewer argument at all: the same public feed regardless of who is looking.
        Member::factory()->create();
        Member::factory()->create();

        $this->assertCount(2, (new RecentPublicCommunityTopics)());
    }
}
