<?php

namespace App\Features\GroupEvent;

use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use Illuminate\Support\Collection;

/** See docs/internals/group-boards.md, "Comment threads page by id". */
final class GroupEventCommentThread
{
    /** Fixed page size (OpenPNE 3 communityEventComment list component). */
    public const SIZE = 20;

    /** @param  Collection<int, GroupEventComment>  $comments  the current page, ascending by id */
    private function __construct(
        public readonly GroupEvent $event,
        public readonly Collection $comments,
        public readonly int $total,
        public readonly bool $ascending,
        public readonly int $page,
        public readonly int $lastPage,
    ) {}

    public static function paginate(GroupEvent $event, mixed $order = null, mixed $page = null): self
    {
        $ascending = is_string($order) && strtolower($order) === 'asc';

        $total = $event->comments()->count();
        $lastPage = max(1, (int) ceil($total / self::SIZE));
        $page = max(1, min((int) ($page ?: 1), $lastPage));

        $comments = $event->comments()->with(['member.avatar.file', 'images.file', 'linkCard.image'])
            ->orderBy('id', $ascending ? 'asc' : 'desc')
            ->forPage($page, self::SIZE)
            ->get();

        if (! $ascending) {
            $comments = $comments->reverse()->values();
        }

        return new self($event, $comments, $total, $ascending, $page, $lastPage);
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
        $params = ['event' => $this->event];
        if ($ascending) {
            $params['order'] = 'asc';
        }
        if ($page > 1) {
            $params['page'] = $page;
        }

        return route('group.events.show', $params);
    }
}
