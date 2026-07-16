<?php

namespace App\View\Components\Gadget;

use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** The viewer's own recent diaries (home; subject = viewer, who is both owner and viewer). */
class DiaryMyList extends DiaryListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        RecentMemberDiaries $diaries,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        if ($subject !== null) {
            $this->entries = self::toEntries($diaries($subject, $subject, self::limit($config)));
        }
    }

    public function render(): View
    {
        return view('components.gadget.diary-my-list');
    }
}
