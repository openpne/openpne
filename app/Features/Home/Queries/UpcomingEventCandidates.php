<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\PlannedItem;
use App\Models\GroupEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Events about to happen, soonest first — a calendar, not news.
 *
 * The only section that reads forward from the publishing instant instead of back over the window,
 * and the reason it is a calendar is the reason it never consults the ledger and never triggers an
 * issue on its own: the same gathering is worth listing every day until it happens, and a week of
 * that would otherwise be a week of issues about nothing new.
 */
final class UpcomingEventCandidates
{
    /** How far ahead the calendar looks. */
    public const DAYS = 7;

    public function alias(): string
    {
        return (new GroupEvent)->getMorphClass();
    }

    /** @return Collection<int, PlannedItem> */
    public function __invoke(CarbonImmutable $now, int $limit, int $days = self::DAYS): Collection
    {
        return GroupEvent::query()
            ->whereHas('group', fn (Builder $group) => $group->where('topic_read_access', TopicReadAccess::Everyone))
            // From the publish day's own midnight, not from the publishing instant: `open_date` is a
            // date (StoreEventRequest) and an event today is still ahead of the reader — the join
            // window runs to the day after it (GroupEvent::isClosed).
            ->where('group_events.open_date', '>=', $now->startOfDay())
            ->where('group_events.open_date', '<=', $now->addDays($days))
            ->withCount(['comments', 'participants'])
            ->orderBy('group_events.open_date')
            ->orderBy('group_events.id')
            ->limit($limit)
            ->get()
            ->map(fn (GroupEvent $event): PlannedItem => new PlannedItem(
                $this->alias(),
                (int) $event->getKey(),
                // The calendar does not rank by engagement, so there is no score to record. The
                // counts are kept as stats all the same: they are the source's own provenance, and
                // a section reads the ledger's score only where it ranked by it.
                0,
                ['comments' => (int) $event->comments_count, 'participants' => (int) $event->participants_count],
                CarbonImmutable::parse($event->created_at),
            ));
    }
}
