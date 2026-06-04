<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The diary sidemenu's "Recently Posted Diaries" box (OpenPNE 3 Diary::getMemberDiaryList):
 * an author's own newest entries the viewer may see, with their comment count.
 */
class RecentMemberDiaries
{
    /** @return Collection<int, Diary> */
    public function __invoke(?Member $viewer, Member $owner, int $limit = 5): Collection
    {
        $query = Diary::where('member_id', $owner->getKey())->withCount('comments');
        DiaryVisibilityScope::apply($query, $viewer, $owner);

        return $query->orderByDesc('created_at')->limit($limit)->get();
    }
}
