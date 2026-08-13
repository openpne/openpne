<?php

namespace Tests\Feature\GroupEvent\Queries;

use App\Features\GroupEvent\Queries\RecentJoinedGroupEvents;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentJoinedGroupEventsTest extends TestCase
{
    use RefreshDatabase;

    private function join(Member $member, Group $group): void
    {
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_returns_events_only_from_communities_the_member_joined(): void
    {
        $viewer = Member::factory()->create();
        $joined = Group::factory()->create();
        $other = Group::factory()->create();
        $this->join($viewer, $joined);

        $mine = GroupEvent::factory()->create(['group_id' => $joined->getKey()]);
        GroupEvent::factory()->create(['group_id' => $other->getKey()]);

        $result = (new RecentJoinedGroupEvents)($viewer);

        $this->assertCount(1, $result);
        $this->assertSame($mine->getKey(), $result->first()->getKey());
    }

    public function test_orders_by_updated_at_desc_and_caps_at_the_limit(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        $this->join($viewer, $group);

        foreach (range(1, 6) as $i) {
            GroupEvent::factory()->create([
                'group_id' => $group->getKey(),
                'updated_at' => now()->subDays(6 - $i),
            ]);
        }

        $result = (new RecentJoinedGroupEvents)($viewer, 3);

        $this->assertCount(3, $result);
        $this->assertTrue($result->first()->updated_at->gt($result->last()->updated_at));
    }

    public function test_loads_the_comment_count(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        $this->join($viewer, $group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        GroupEventComment::factory()->count(3)->create(['group_event_id' => $event->getKey()]);

        $this->assertSame(3, (int) (new RecentJoinedGroupEvents)($viewer)->first()->comments_count);
    }
}
