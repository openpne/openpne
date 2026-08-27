<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\HomeIssueLedger;
use App\Features\Home\HomeIssueSection;
use App\Models\GroupEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Events announced in groups any member may read, ranked by comments plus RSVPs.
 *
 * Both count, because an event draws its interest in two ways and one of them is silent: a gathering
 * fifty people signed up for without saying a word is the bigger news.
 */
final class EventStoryCandidates implements StoryCandidates
{
    public function alias(): string
    {
        return (new GroupEvent)->getMorphClass();
    }

    public function __invoke(HomeIssueWindow $window, int $limit): Collection
    {
        $query = $this->readableByEveryMember();
        $window->apply($query, 'group_events.created_at');
        HomeIssueLedger::excludeFeatured($query, HomeIssueSection::Stories, $this->alias(), 'group_events.id');

        return $query
            // The sum, so the limit really does take the top $limit: ordering by the two counts in
            // turn would rank 9 comments above 1 comment and 50 RSVPs. Both engines resolve a select
            // alias inside an ORDER BY expression, which is what keeps the subqueries from being
            // written out a second time here.
            ->orderByRaw('comments_count + participants_count desc')
            ->orderByDesc('group_events.created_at')
            ->orderByDesc('group_events.id')
            ->limit($limit)
            ->get()
            ->map($this->item(...));
    }

    public function find(int $id): ?PlannedItem
    {
        $event = $this->readableByEveryMember()->whereKey($id)->first();

        return $event === null ? null : $this->item($event);
    }

    /** @return Builder<GroupEvent> */
    private function readableByEveryMember(): Builder
    {
        return GroupEvent::query()
            ->whereHas('group', fn (Builder $group) => $group->where('topic_read_access', TopicReadAccess::Everyone))
            ->withCount(['comments', 'participants']);
    }

    private function item(GroupEvent $event): PlannedItem
    {
        $comments = (int) $event->comments_count;
        $participants = (int) $event->participants_count;

        return new PlannedItem(
            $this->alias(),
            (int) $event->getKey(),
            $comments + $participants,
            ['comments' => $comments, 'participants' => $participants],
            CarbonImmutable::parse($event->created_at),
        );
    }
}
