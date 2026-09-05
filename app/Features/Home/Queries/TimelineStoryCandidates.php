<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\HomeIssueLedger;
use App\Features\Home\HomeIssueSection;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class TimelineStoryCandidates implements StoryCandidates
{
    public function alias(): string
    {
        return (new TimelinePost)->getMorphClass();
    }

    public function __invoke(HomeIssueWindow $window, int $limit): Collection
    {
        $query = $this->readableByEveryMember();
        $window->apply($query, 'timeline_posts.created_at');
        HomeIssueLedger::excludeFeatured($query, HomeIssueSection::Stories, $this->alias(), 'timeline_posts.id');

        return $query
            ->orderByDesc('replies_count')
            ->orderByDesc('timeline_posts.created_at')
            ->orderByDesc('timeline_posts.id')
            ->limit($limit)
            ->get()
            ->map($this->item(...));
    }

    public function find(int $id): ?PlannedItem
    {
        $post = $this->readableByEveryMember()->whereKey($id)->first();

        return $post === null ? null : $this->item($post);
    }

    /**
     * TimelineFeedScope::applyMembersOnly's audience test, minus its block filter: a block is a
     * relation between two members and the publisher is neither of them. A reply is not a story — it
     * is part of one, and the post it answers is the candidate.
     *
     * @return Builder<TimelinePost>
     */
    private function readableByEveryMember(): Builder
    {
        return TimelinePost::query()
            ->whereNull('timeline_posts.in_reply_to_id')
            ->where('timeline_posts.visibility', '<=', Visibility::Members->value)
            ->withCount('replies');
    }

    private function item(TimelinePost $post): PlannedItem
    {
        $replies = (int) $post->replies_count;

        return new PlannedItem(
            $this->alias(),
            (int) $post->getKey(),
            $replies,
            ['replies' => $replies],
            CarbonImmutable::parse($post->created_at),
        );
    }
}
