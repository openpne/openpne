<?php

namespace App\View\Components\Gadget;

use App\Features\Diary\Queries\ListFriendDiaries;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** Recently posted diaries by the subject's friends (home; subject = viewer). */
class DiaryFriendList extends DiaryListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        ListFriendDiaries $diaries,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        if ($subject !== null) {
            $this->entries = self::toEntries($diaries->take($subject, self::limit($config)));
        }
    }

    public function render(): View
    {
        return view('components.gadget.diary-friend-list');
    }
}
