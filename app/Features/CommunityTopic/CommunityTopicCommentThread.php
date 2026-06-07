<?php

namespace App\Features\CommunityTopic;

use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use Illuminate\Support\Collection;

/**
 * OpenPNE 3 communityTopicComment list pager (sfReversibleDoctrinePager): comments page by their
 * `id` at a fixed size, with a reversible order. The default (DESC) fetches the newest page first
 * but always lists a page oldest-first; `order=asc` walks from the first comment. "Older"/"Newer"
 * follow comment age, not page index.
 *
 * Ordering is by `id` (OpenPNE 3 setSqlOrderColumn('id')), not `number`: `number` is a racy max+1
 * label that migrated data may carry out of order or duplicated, so paging by it would drift the
 * page boundaries from OpenPNE 3. `id` is the monotonic insertion order.
 *
 * The list has no size switch: OpenPNE 3 fixes it at 20.
 */
final class CommunityTopicCommentThread
{
    /** Fixed page size (OpenPNE 3 communityTopicComment list component). */
    public const SIZE = 20;

    /** @param  Collection<int, CommunityTopicComment>  $comments  the current page, ascending by number */
    private function __construct(
        public readonly CommunityTopic $topic,
        public readonly Collection $comments,
        public readonly int $total,
        public readonly bool $ascending,
        public readonly int $page,
        public readonly int $lastPage,
    ) {}

    public static function paginate(CommunityTopic $topic, mixed $order = null, mixed $page = null): self
    {
        $ascending = is_string($order) && strtolower($order) === 'asc';

        $total = $topic->comments()->count();
        $lastPage = max(1, (int) ceil($total / self::SIZE));
        $page = max(1, min((int) ($page ?: 1), $lastPage));

        $comments = $topic->comments()->with(['member', 'images.file'])
            ->orderBy('id', $ascending ? 'asc' : 'desc')
            ->forPage($page, self::SIZE)
            ->get();

        // The list is always rendered ascending: a descending page is reversed back for display.
        if (! $ascending) {
            $comments = $comments->reverse()->values();
        }

        return new self($topic, $comments, $total, $ascending, $page, $lastPage);
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function hasOlder(): bool
    {
        return $this->ascending ? $this->page > 1 : $this->page < $this->lastPage;
    }

    public function hasNewer(): bool
    {
        return $this->ascending ? $this->page < $this->lastPage : $this->page > 1;
    }

    public function olderPage(): int
    {
        return $this->ascending ? $this->page - 1 : $this->page + 1;
    }

    public function newerPage(): int
    {
        return $this->ascending ? $this->page + 1 : $this->page - 1;
    }

    public function firstNumber(): ?int
    {
        return $this->comments->first()?->number;
    }

    public function lastNumber(): ?int
    {
        return $this->comments->last()?->number;
    }

    /** A show-page URL carrying this view state; order is dropped when default (DESC), page when 1. */
    public function link(int $page, bool $ascending): string
    {
        $params = ['topic' => $this->topic];
        if ($ascending) {
            $params['order'] = 'asc';
        }
        if ($page > 1) {
            $params['page'] = $page;
        }

        return route('communityTopic.show', $params);
    }
}
