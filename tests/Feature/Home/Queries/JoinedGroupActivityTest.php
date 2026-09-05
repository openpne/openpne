<?php

namespace Tests\Feature\Home\Queries;

use App\Features\Home\Queries\JoinedGroupActivity;
use App\Models\Group;
use App\Models\GroupEvent;
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
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'updated_at' => now()]);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(GroupEvent::class, $result[0]); // event is newer
        $this->assertInstanceOf(GroupTopic::class, $result[1]);
    }

    public function test_keeps_a_topic_and_an_event_that_share_a_numeric_id(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);

        // A topic and an event forced onto the same primary key — separate tables, so a shared id is
        // legal — which a keyed Eloquent merge would collapse into one, with explicit ids so it holds
        // whatever the AUTO_INCREMENT state.
        $topic = GroupTopic::factory()->create(['id' => 4242, 'group_id' => $group->getKey()]);
        $event = GroupEvent::factory()->create(['id' => 4242, 'group_id' => $group->getKey()]);
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
        GroupEvent::factory()->create(['group_id' => $group->getKey(), 'updated_at' => $at]);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertInstanceOf(GroupTopic::class, $result[0]);
        $this->assertInstanceOf(GroupEvent::class, $result[1]);
    }

    public function test_eager_loads_the_group_image_on_every_row(): void
    {
        // Both a topic row and an event row are asserted to arrive with the group relation loaded,
        // which catches a dropped eager load on either feeder.
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);

        GroupTopic::factory()->create(['group_id' => $group->getKey(), 'updated_at' => now()->subHour()]);
        GroupEvent::factory()->create(['group_id' => $group->getKey(), 'updated_at' => now()]);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertInstanceOf(GroupEvent::class, $result[0]);
        $this->assertInstanceOf(GroupTopic::class, $result[1]);
        foreach ($result as $row) {
            $this->assertTrue($row->group->relationLoaded('image'), "the owning group's image must be eager-loaded per feeder query");
        }
    }

    public function test_caps_at_the_limit_across_both_sources(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);

        GroupTopic::factory()->count(4)->create(['group_id' => $group->getKey()]);
        GroupEvent::factory()->count(4)->create(['group_id' => $group->getKey()]);

        $this->assertCount(5, app(JoinedGroupActivity::class)($viewer, 5));
    }

    public function test_a_switched_off_board_leaves_the_events(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);
        GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        GroupEvent::factory()->create(['group_id' => $group->getKey()]);

        $this->setSnsSetting(Feature::GroupTopic->settingKey(), false);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(GroupEvent::class, $result[0]);
    }

    public function test_a_switched_off_calendar_leaves_the_topics(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);
        GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        GroupEvent::factory()->create(['group_id' => $group->getKey()]);

        $this->setSnsSetting(Feature::GroupEvent->settingKey(), false);

        $result = app(JoinedGroupActivity::class)($viewer);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(GroupTopic::class, $result[0]);
    }

    public function test_switching_communities_off_takes_both_halves(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);
        GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        GroupEvent::factory()->create(['group_id' => $group->getKey()]);

        $this->setSnsSetting(Feature::Group->settingKey(), false);

        $this->assertCount(0, app(JoinedGroupActivity::class)($viewer));
    }
}
