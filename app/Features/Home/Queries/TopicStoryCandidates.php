<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\HomeIssueLedger;
use App\Features\Home\HomeIssueSection;
use App\Models\GroupTopic;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class TopicStoryCandidates implements StoryCandidates
{
    public function alias(): string
    {
        return (new GroupTopic)->getMorphClass();
    }

    public function __invoke(HomeIssueWindow $window, int $limit): Collection
    {
        $query = $this->readableByEveryMember();
        $window->apply($query, 'group_topics.created_at');
        HomeIssueLedger::excludeFeatured($query, HomeIssueSection::Stories, $this->alias(), 'group_topics.id');

        return $query
            ->orderByDesc('comments_count')
            ->orderByDesc('group_topics.created_at')
            ->orderByDesc('group_topics.id')
            ->limit($limit)
            ->get()
            ->map($this->item(...));
    }

    public function find(int $id): ?PlannedItem
    {
        $topic = $this->readableByEveryMember()->whereKey($id)->first();

        return $topic === null ? null : $this->item($topic);
    }

    /**
     * The group's own read column, the one gate the board, its events and its talk all answer from
     * (GroupTalkAccess). Membership is what a MembersOnly group asks for, and no membership is
     * every member.
     *
     * @return Builder<GroupTopic>
     */
    private function readableByEveryMember(): Builder
    {
        return GroupTopic::query()
            ->whereHas('group', fn (Builder $group) => $group->where('topic_read_access', TopicReadAccess::Everyone))
            ->withCount('comments');
    }

    private function item(GroupTopic $topic): PlannedItem
    {
        $comments = (int) $topic->comments_count;

        return new PlannedItem(
            $this->alias(),
            (int) $topic->getKey(),
            $comments,
            ['comments' => $comments],
            CarbonImmutable::parse($topic->created_at),
        );
    }
}
