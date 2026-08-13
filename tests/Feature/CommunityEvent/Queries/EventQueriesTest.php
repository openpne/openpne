<?php

namespace Tests\Feature\CommunityEvent\Queries;

use App\Features\CommunityEvent\Queries\EventParticipants;
use App\Features\CommunityEvent\Queries\ListCommunityEvents;
use App\Features\CommunityEvent\Queries\RecentCommunityEvents;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventQueriesTest extends TestCase
{
    use RefreshDatabase;

    private function eventWithUpdatedAt(Group $group, string $updatedAt): CommunityEvent
    {
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey()]);
        DB::table('community_events')->where('id', $event->getKey())->update(['updated_at' => $updatedAt]);

        return $event->fresh();
    }

    public function test_lists_events_most_recently_active_first_with_comment_counts(): void
    {
        $group = Group::factory()->create();
        $stale = $this->eventWithUpdatedAt($group, now()->subDays(3)->toDateTimeString());
        $active = $this->eventWithUpdatedAt($group, now()->subHour()->toDateTimeString());
        CommunityEventComment::factory()->count(2)->create(['community_event_id' => $active->getKey()]);

        $page = app(ListCommunityEvents::class)($group);

        $this->assertSame([$active->getKey(), $stale->getKey()], $page->pluck('id')->all());
        $this->assertSame(2, $page->firstWhere('id', $active->getKey())->comments_count);
    }

    public function test_recent_events_are_capped(): void
    {
        $group = Group::factory()->create();
        CommunityEvent::factory()->count(RecentCommunityEvents::LIMIT + 2)->create(['community_id' => $group->getKey()]);

        $recent = app(RecentCommunityEvents::class)($group);

        $this->assertCount(RecentCommunityEvents::LIMIT, $recent);
    }

    public function test_participants_roster_lists_joined_members(): void
    {
        $group = Group::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey()]);
        $a = Member::factory()->create();
        $b = Member::factory()->create();
        $event->participants()->attach([$a->getKey(), $b->getKey()]);

        $roster = app(EventParticipants::class)($event);

        $this->assertEqualsCanonicalizing([$a->getKey(), $b->getKey()], $roster->pluck('id')->all());
    }
}
