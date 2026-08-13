<?php

namespace Tests\Feature\GroupEvent\Queries;

use App\Features\GroupEvent\Queries\RecentPublicGroupEvents;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentPublicGroupEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_events_only_from_public_communities(): void
    {
        $public = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $membersOnly = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);

        $shown = GroupEvent::factory()->create(['group_id' => $public->getKey()]);
        GroupEvent::factory()->create(['group_id' => $membersOnly->getKey()]);

        $result = (new RecentPublicGroupEvents)();

        $this->assertCount(1, $result);
        $this->assertSame($shown->getKey(), $result->first()->getKey());
    }

    public function test_orders_by_updated_at_desc_and_caps_at_the_limit(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        foreach (range(1, 6) as $i) {
            GroupEvent::factory()->create([
                'group_id' => $group->getKey(),
                'updated_at' => now()->subDays(6 - $i), // i=6 newest
            ]);
        }

        $result = (new RecentPublicGroupEvents)(3);

        $this->assertCount(3, $result);
        $this->assertTrue($result->first()->updated_at->gt($result->last()->updated_at));
    }

    public function test_loads_the_comment_count(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        GroupEventComment::factory()->count(3)->create(['group_event_id' => $event->getKey()]);

        $this->assertSame(3, (int) (new RecentPublicGroupEvents)()->first()->comments_count);
    }

    public function test_is_viewer_independent(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        GroupEvent::factory()->count(2)->create(['group_id' => $group->getKey()]);

        // No viewer argument at all: the same public feed regardless of who is looking.
        Member::factory()->create();
        Member::factory()->create();

        $this->assertCount(2, (new RecentPublicGroupEvents)());
    }
}
