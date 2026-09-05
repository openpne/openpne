<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\HomeIssueLedger;
use App\Features\Home\HomeIssueSection;
use App\Models\Diary;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class DiaryStoryCandidates implements StoryCandidates
{
    public function alias(): string
    {
        return (new Diary)->getMorphClass();
    }

    public function __invoke(HomeIssueWindow $window, int $limit): Collection
    {
        $query = $this->readableByEveryMember();
        $window->apply($query, 'diaries.created_at');
        HomeIssueLedger::excludeFeatured($query, HomeIssueSection::Stories, $this->alias(), 'diaries.id');

        return $query
            ->orderByDesc('comments_count')
            ->orderByDesc('diaries.created_at')
            ->orderByDesc('diaries.id')
            ->limit($limit)
            ->get()
            ->map($this->item(...));
    }

    public function find(int $id): ?PlannedItem
    {
        $diary = $this->readableByEveryMember()->whereKey($id)->first();

        return $diary === null ? null : $this->item($diary);
    }

    /**
     * DiaryVisibilityScope::applyFeed's tier, minus its block filter (the publisher has no viewer to
     * apply one for). Open sits below Members on the monotonic scale and is included; the web-public
     * switch is a guest question and no guest reaches an issue.
     *
     * @return Builder<Diary>
     */
    private function readableByEveryMember(): Builder
    {
        return Diary::query()
            ->where('diaries.visibility', '<=', Visibility::Members->value)
            ->withCount(['comments', 'images']);
    }

    private function item(Diary $diary): PlannedItem
    {
        $comments = (int) $diary->comments_count;

        return new PlannedItem(
            $this->alias(),
            (int) $diary->getKey(),
            $comments,
            ['comments' => $comments, 'images' => (int) $diary->images_count],
            CarbonImmutable::parse($diary->created_at),
        );
    }
}
