<?php

namespace App\View\Components\Gadget;

use App\Features\Diary\Queries\ListRecentDiaries;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** Recently posted diaries across the whole SNS (home; subject = viewer). */
class DiaryList extends DiaryListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        ListRecentDiaries $diaries,
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
        return view('components.gadget.diary-list');
    }
}
