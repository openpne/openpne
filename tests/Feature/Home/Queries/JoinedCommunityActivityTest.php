<?php

namespace Tests\Feature\Home\Queries;

use App\Features\Home\Queries\JoinedCommunityActivity;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JoinedCommunityActivityTest extends TestCase
{
    use RefreshDatabase;

    private function joinedCommunity(Member $viewer): Community
    {
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create([
            'community_id' => $community->getKey(),
            'member_id' => $viewer->getKey(),
        ]);

        return $community;
    }

    public function test_merges_topics_and_events_newest_first(): void
    {
        $viewer = Member::factory()->create();
        $community = $this->joinedCommunity($viewer);

        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'updated_at' => now()->subHour()]);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'updated_at' => now()]);

        $result = app(JoinedCommunityActivity::class)($viewer);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(CommunityEvent::class, $result[0]); // event is newer
        $this->assertInstanceOf(CommunityTopic::class, $result[1]);
    }

    public function test_keeps_a_topic_and_an_event_that_share_a_numeric_id(): void
    {
        $viewer = Member::factory()->create();
        $community = $this->joinedCommunity($viewer);

        // Force a topic and an event onto the same primary-key value (topics and events are separate
        // tables, so a shared id is legal). A keyed Eloquent merge would collapse them into one;
        // toBase()->concat() keeps both. Explicit ids so it holds regardless of AUTO_INCREMENT state.
        $topic = CommunityTopic::factory()->create(['id' => 4242, 'community_id' => $community->getKey()]);
        $event = CommunityEvent::factory()->create(['id' => 4242, 'community_id' => $community->getKey()]);
        $this->assertSame($topic->getKey(), $event->getKey(), 'test precondition: the ids collide');

        $result = app(JoinedCommunityActivity::class)($viewer);

        $this->assertCount(2, $result);
    }

    public function test_ties_on_updated_at_place_topics_before_events(): void
    {
        $viewer = Member::factory()->create();
        $community = $this->joinedCommunity($viewer);
        $at = now();

        CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'updated_at' => $at]);
        CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'updated_at' => $at]);

        $result = app(JoinedCommunityActivity::class)($viewer);

        $this->assertInstanceOf(CommunityTopic::class, $result[0]);
        $this->assertInstanceOf(CommunityEvent::class, $result[1]);
    }

    public function test_eager_loads_the_community_image_on_every_row(): void
    {
        // The digest serializes each row's community image; without the per-feeder community.image
        // eager load every row would lazy-load its own. Assert both a topic row and an event row
        // arrive with the relation loaded (catches a dropped eager load on either feeder directly).
        $viewer = Member::factory()->create();
        $community = $this->joinedCommunity($viewer);

        CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'updated_at' => now()->subHour()]);
        CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'updated_at' => now()]);

        $result = app(JoinedCommunityActivity::class)($viewer);

        $this->assertInstanceOf(CommunityEvent::class, $result[0]);
        $this->assertInstanceOf(CommunityTopic::class, $result[1]);
        foreach ($result as $row) {
            $this->assertTrue($row->community->relationLoaded('image'), 'community.image must be eager-loaded per feeder query');
        }
    }

    public function test_caps_at_the_limit_across_both_sources(): void
    {
        $viewer = Member::factory()->create();
        $community = $this->joinedCommunity($viewer);

        CommunityTopic::factory()->count(4)->create(['community_id' => $community->getKey()]);
        CommunityEvent::factory()->count(4)->create(['community_id' => $community->getKey()]);

        $this->assertCount(5, app(JoinedCommunityActivity::class)($viewer, 5));
    }
}
