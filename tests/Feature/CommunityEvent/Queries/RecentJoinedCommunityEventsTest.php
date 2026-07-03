<?php

namespace Tests\Feature\CommunityEvent\Queries;

use App\Features\CommunityEvent\Queries\RecentJoinedCommunityEvents;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentJoinedCommunityEventsTest extends TestCase
{
    use RefreshDatabase;

    private function join(Member $member, Community $community): void
    {
        CommunityMember::factory()->member()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_returns_events_only_from_communities_the_member_joined(): void
    {
        $viewer = Member::factory()->create();
        $joined = Community::factory()->create();
        $other = Community::factory()->create();
        $this->join($viewer, $joined);

        $mine = CommunityEvent::factory()->create(['community_id' => $joined->getKey()]);
        CommunityEvent::factory()->create(['community_id' => $other->getKey()]);

        $result = (new RecentJoinedCommunityEvents)($viewer);

        $this->assertCount(1, $result);
        $this->assertSame($mine->getKey(), $result->first()->getKey());
    }

    public function test_orders_by_updated_at_desc_and_caps_at_the_limit(): void
    {
        $viewer = Member::factory()->create();
        $community = Community::factory()->create();
        $this->join($viewer, $community);

        foreach (range(1, 6) as $i) {
            CommunityEvent::factory()->create([
                'community_id' => $community->getKey(),
                'updated_at' => now()->subDays(6 - $i),
            ]);
        }

        $result = (new RecentJoinedCommunityEvents)($viewer, 3);

        $this->assertCount(3, $result);
        $this->assertTrue($result->first()->updated_at->gt($result->last()->updated_at));
    }

    public function test_loads_the_comment_count(): void
    {
        $viewer = Member::factory()->create();
        $community = Community::factory()->create();
        $this->join($viewer, $community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        CommunityEventComment::factory()->count(3)->create(['community_event_id' => $event->getKey()]);

        $this->assertSame(3, (int) (new RecentJoinedCommunityEvents)($viewer)->first()->comments_count);
    }
}
