<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;

class ShowDiary
{
    public function __invoke(Member $viewer, int $diaryId): ?Diary
    {
        $diary = Diary::with('member')->find($diaryId);

        if ($diary === null) {
            return null;
        }

        $owner = $diary->member;

        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return null;
        }

        $clearance = Visibility::clearanceFor($viewer, $owner);
        if ($diary->visibility->value > $clearance->value) {
            return null;
        }

        return $diary;
    }
}
