<?php

namespace Tests\Feature\CommunityEvent\Queries;

use App\Features\CommunityEvent\Queries\RecentPublicCommunityEvents;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentPublicCommunityEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_events_only_from_public_communities(): void
    {
        $public = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $membersOnly = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);

        $shown = CommunityEvent::factory()->create(['community_id' => $public->getKey()]);
        CommunityEvent::factory()->create(['community_id' => $membersOnly->getKey()]);

        $result = (new RecentPublicCommunityEvents)();

        $this->assertCount(1, $result);
        $this->assertSame($shown->getKey(), $result->first()->getKey());
    }

    public function test_orders_by_updated_at_desc_and_caps_at_the_limit(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        foreach (range(1, 6) as $i) {
            CommunityEvent::factory()->create([
                'community_id' => $community->getKey(),
                'updated_at' => now()->subDays(6 - $i), // i=6 newest
            ]);
        }

        $result = (new RecentPublicCommunityEvents)(3);

        $this->assertCount(3, $result);
        $this->assertTrue($result->first()->updated_at->gt($result->last()->updated_at));
    }

    public function test_loads_the_comment_count(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        CommunityEventComment::factory()->count(3)->create(['community_event_id' => $event->getKey()]);

        $this->assertSame(3, (int) (new RecentPublicCommunityEvents)()->first()->comments_count);
    }

    public function test_is_viewer_independent(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        CommunityEvent::factory()->count(2)->create(['community_id' => $community->getKey()]);

        // No viewer argument at all: the same public feed regardless of who is looking.
        Member::factory()->create();
        Member::factory()->create();

        $this->assertCount(2, (new RecentPublicCommunityEvents)());
    }
}
