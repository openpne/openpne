<?php

namespace App\View\Components\Gadget;

use App\Features\Diary\Queries\DiaryCommentHistory as DiaryCommentHistoryQuery;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/** Other members' diaries the viewer commented on, latest comment first (home; subject = viewer). */
class DiaryCommentHistory extends DiaryListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        DiaryCommentHistoryQuery $diaries,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        if ($subject !== null) {
            // The row date is the diary's last comment time (query-computed), not its created_at.
            $this->entries = self::mapEntries(
                $diaries($subject, self::limit($config)),
                static fn (Diary $diary) => Carbon::parse($diary->last_comment_time),
            );
        }
    }

    public function render(): View
    {
        return view('components.gadget.diary-comment-history');
    }
}
