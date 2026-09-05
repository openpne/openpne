<?php

namespace App\Features\Diary;

use App\Models\Diary;
use App\Models\DiaryComment;
use Illuminate\Support\Collection;

/**
 * OpenPNE 3's `diaryComment` list pager: a page is fetched by `number` in either order and always
 * listed oldest-first (docs/internals/diary.md, "The thread pages by number").
 */
final class DiaryCommentThread
{
    public const SIZES = [20, 100];

    /** @param  Collection<int, DiaryComment>  $comments  the current page, ascending by number */
    private function __construct(
        public readonly Diary $diary,
        public readonly Collection $comments,
        public readonly int $total,
        public readonly int $size,
        public readonly bool $ascending,
        public readonly int $page,
        public readonly int $lastPage,
    ) {}

    public static function paginate(Diary $diary, mixed $size = null, mixed $order = null, mixed $page = null): self
    {
        $size = in_array((int) $size, self::SIZES, true) ? (int) $size : self::SIZES[0];
        $ascending = is_string($order) && strtolower($order) === 'asc';

        $total = $diary->comments()->count();
        $lastPage = max(1, (int) ceil($total / $size));
        $page = max(1, min((int) ($page ?: 1), $lastPage));

        $comments = $diary->comments()->with(['member', 'images.file', 'linkCard.image'])
            ->orderBy('number', $ascending ? 'asc' : 'desc')
            ->forPage($page, $size)
            ->get();

        if (! $ascending) {
            $comments = $comments->reverse()->values();
        }

        return new self($diary, $comments, $total, $size, $ascending, $page, $lastPage);
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    /** OpenPNE 3 offers the size switch once the smallest size would split the thread. */
    public function offersSizeSwitch(): bool
    {
        return min(self::SIZES) < $this->total;
    }

    /** @return list<int> */
    public function otherSizes(): array
    {
        return array_values(array_filter(self::SIZES, fn ($n) => $n !== $this->size));
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

    public function link(int $page, int $size, bool $ascending): string
    {
        $params = ['diary' => $this->diary, 'size' => $size];
        if ($ascending) {
            $params['order'] = 'asc';
        }
        if ($page > 1) {
            $params['page'] = $page;
        }

        return route('diary.show', $params);
    }
}
