<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryAccess;
use App\Models\Diary;
use App\Models\Member;

class ShowDiary
{
    public function __invoke(Member $viewer, int $diaryId): ?Diary
    {
        $diary = Diary::with(['member', 'images.file'])->find($diaryId);

        if ($diary === null) {
            return null;
        }

        return DiaryAccess::canView($viewer, $diary) ? $diary : null;
    }
}
