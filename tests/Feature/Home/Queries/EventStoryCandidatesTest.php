<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Queries\EventStoryCandidates;
use App\Features\Home\Queries\StoryCandidates;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupEventMember;
use Carbon\CarbonImmutable;

class EventStoryCandidatesTest extends StoryCandidatesTestCase
{
    protected function candidates(): StoryCandidates
    {
        return app(EventStoryCandidates::class);
    }

    /** Engagement alternates a comment and an RSVP, since the kind ranks by their sum. */
    protected function readable(CarbonImmutable $at, int $engagement = 0): GroupEvent
    {
        $event = $this->at($at, fn (): GroupEvent => GroupEvent::factory()->create());
        for ($unit = 1; $unit <= $engagement; $unit++) {
            if ($unit % 2 === 1) {
                GroupEventComment::factory()->create(['group_event_id' => $event->getKey(), 'number' => $unit]);
            } else {
                GroupEventMember::factory()->create(['group_event_id' => $event->getKey()]);
            }
        }

        return $event;
    }

    protected function walledOff(CarbonImmutable $at): GroupEvent
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);

        return $this->at($at, fn (): GroupEvent => GroupEvent::factory()->create(['group_id' => $group->getKey()]));
    }

    public function test_rsvps_and_comments_rank_as_one_sum(): void
    {
        $window = $this->window();
        $talked = $this->at($window->end, fn (): GroupEvent => GroupEvent::factory()->create());
        GroupEventComment::factory()->create(['group_event_id' => $talked->getKey(), 'number' => 1]);
        GroupEventComment::factory()->create(['group_event_id' => $talked->getKey(), 'number' => 2]);
        $joined = $this->at($window->end->subHour(), fn (): GroupEvent => GroupEvent::factory()->create());
        GroupEventComment::factory()->create(['group_event_id' => $joined->getKey(), 'number' => 1]);
        GroupEventMember::factory()->count(3)->create(['group_event_id' => $joined->getKey()]);

        $items = $this->candidates()($window, 10);

        $this->assertSame([$joined->getKey(), $talked->getKey()], $this->ids($items));
        $this->assertSame(['comments' => 1, 'participants' => 3], $items->first()?->stats);
    }
}
