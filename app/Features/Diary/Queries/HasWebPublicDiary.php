<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryVisibility;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;

/**
 * Whether a signed-out visitor may open an author's archive at all. Owner-level on purpose: the
 * page's own month or keyword narrowing must not decide it (docs/internals/diary.md, "The archive").
 */
class HasWebPublicDiary
{
    public function __invoke(Member $owner): bool
    {
        return DiaryVisibility::allowsWebPublic()
            && Diary::query()
                ->where('member_id', $owner->getKey())
                ->where('visibility', Visibility::Open->value)
                ->exists();
    }
}
