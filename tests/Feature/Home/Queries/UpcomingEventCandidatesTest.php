<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\Queries\UpcomingEventCandidates;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupEventMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class UpcomingEventCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
    }

    private function on(CarbonImmutable $day, ?Group $group = null): GroupEvent
    {
        return GroupEvent::factory()->create([
            'open_date' => $day->toDateString(),
            'group_id' => ($group ?? Group::factory()->create())->getKey(),
        ]);
    }

    /**
     * @param  Collection<int, PlannedItem>  $items
     * @return list<int>
     */
    private function ids($items): array
    {
        return $items->map(fn (PlannedItem $item): int => $item->sourceId)->values()->all();
    }

    public function test_the_calendar_runs_from_today_through_the_seventh_day_out_in_date_order(): void
    {
        $now = $this->now();
        $this->on($now->subDay());
        $today = $this->on($now);
        $later = $this->on($now->addDays(3));
        $edge = $this->on($now->addDays(UpcomingEventCandidates::DAYS));
        $this->on($now->addDays(UpcomingEventCandidates::DAYS + 1));

        $items = app(UpcomingEventCandidates::class)($now, 10);

        $this->assertSame([$today->getKey(), $later->getKey(), $edge->getKey()], $this->ids($items));
        $this->assertSame([$today->getKey(), $later->getKey()], $this->ids(app(UpcomingEventCandidates::class)($now, 2)));
    }

    public function test_only_an_event_every_member_may_read_is_on_the_calendar_and_its_counts_ride_along_unscored(): void
    {
        $now = $this->now();
        $walled = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $this->on($now->addDay(), $walled);
        $open = $this->on($now->addDay());
        GroupEventComment::factory()->create(['group_event_id' => $open->getKey(), 'number' => 1]);
        GroupEventMember::factory()->count(2)->create(['group_event_id' => $open->getKey()]);

        $items = app(UpcomingEventCandidates::class)($now, 10);

        $this->assertSame([$open->getKey()], $this->ids($items));
        $this->assertSame(0, $items->first()?->score);
        $this->assertSame(['comments' => 1, 'participants' => 2], $items->first()?->stats);
    }
}
