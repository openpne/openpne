<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryVisibility;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;

/**
 * Whether an author has anything at all for a guest — OpenPNE 3 DiaryTable::hasOpenDiary, the gate
 * that decides whether a signed-out visitor may open the author's archive.
 *
 * Owner-level on purpose: the page's own month / keyword narrowing must not decide it, or an empty
 * month on an author who does publish would bounce the visitor to login.
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
