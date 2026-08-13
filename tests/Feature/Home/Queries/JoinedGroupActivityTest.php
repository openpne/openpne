<?php

namespace Tests\Feature\Home\Queries;

use App\Features\Home\Queries\JoinedGroupActivity;
use App\Models\CommunityEvent;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JoinedGroupActivityTest extends TestCase
{
    use RefreshDatabase;

    private function joinedGroup(Member $viewer): Group
    {
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $viewer->getKey(),
        ]);

        return $group;
    }

    public function test_merges_topics_and_events_newest_first(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);

        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'updated_at' => now()->subHour()]);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'updated_at' => now()]);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(CommunityEvent::class, $result[0]); // event is newer
        $this->assertInstanceOf(GroupTopic::class, $result[1]);
    }

    public function test_keeps_a_topic_and_an_event_that_share_a_numeric_id(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);

        // Force a topic and an event onto the same primary-key value (topics and events are separate
        // tables, so a shared id is legal). A keyed Eloquent merge would collapse them into one;
        // toBase()->concat() keeps both. Explicit ids so it holds regardless of AUTO_INCREMENT state.
        $topic = GroupTopic::factory()->create(['id' => 4242, 'group_id' => $group->getKey()]);
        $event = CommunityEvent::factory()->create(['id' => 4242, 'community_id' => $group->getKey()]);
        $this->assertSame($topic->getKey(), $event->getKey(), 'test precondition: the ids collide');

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertCount(2, $result);
    }

    public function test_ties_on_updated_at_place_topics_before_events(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);
        $at = now();

        GroupTopic::factory()->create(['group_id' => $group->getKey(), 'updated_at' => $at]);
        CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'updated_at' => $at]);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertInstanceOf(GroupTopic::class, $result[0]);
        $this->assertInstanceOf(CommunityEvent::class, $result[1]);
    }

    public function test_eager_loads_the_group_image_on_every_row(): void
    {
        // The digest serializes each row's group image; without the per-feeder eager load on the
        // owning group every row would lazy-load its own. Assert both a topic row and an event row
        // arrive with the relation loaded (catches a dropped eager load on either feeder directly).
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);

        GroupTopic::factory()->create(['group_id' => $group->getKey(), 'updated_at' => now()->subHour()]);
        CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'updated_at' => now()]);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertInstanceOf(CommunityEvent::class, $result[0]);
        $this->assertInstanceOf(GroupTopic::class, $result[1]);
        foreach ($result as $row) {
            $owner = $row instanceof GroupTopic ? $row->group : $row->community;
            $this->assertTrue($owner->relationLoaded('image'), "the owning group's image must be eager-loaded per feeder query");
        }
    }

    public function test_caps_at_the_limit_across_both_sources(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);

        GroupTopic::factory()->count(4)->create(['group_id' => $group->getKey()]);
        CommunityEvent::factory()->count(4)->create(['community_id' => $group->getKey()]);

        $this->assertCount(5, app(JoinedGroupActivity::class)($viewer, 5));
    }

    public function test_a_switched_off_board_leaves_the_events(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);
        GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        CommunityEvent::factory()->create(['community_id' => $group->getKey()]);

        $this->setSnsSetting(Feature::GroupTopic->settingKey(), false);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(CommunityEvent::class, $result[0]);
    }

    public function test_a_switched_off_calendar_leaves_the_topics(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);
        GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        CommunityEvent::factory()->create(['community_id' => $group->getKey()]);

        $this->setSnsSetting(Feature::CommunityEvent->settingKey(), false);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(GroupTopic::class, $result[0]);
    }

    public function test_switching_communities_off_takes_both_halves(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);
        GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        CommunityEvent::factory()->create(['community_id' => $group->getKey()]);

        $this->setSnsSetting(Feature::Group->settingKey(), false);

        $this->assertCount(0, app(JoinedGroupActivity::class)($viewer));
    }
}
