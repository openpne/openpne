<?php

namespace App\View\Components\Gadget;

use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** A profile owner's recent diaries (profile; subject = owner, viewer = the current member). */
class DiaryMemberList extends DiaryListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        RecentMemberDiaries $diaries,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        if ($subject !== null) {
            /** @var Member|null $viewer */
            $viewer = auth()->user();
            $this->entries = self::toEntries($diaries($viewer, $subject, self::limit($config)));
        }
    }

    public function render(): View
    {
        return view('components.gadget.diary-member-list');
    }
}
