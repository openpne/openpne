<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Support\Collection;

class RecentMemberDiaries
{
    /** @return Collection<int, Diary> */
    public function __invoke(?Member $viewer, Member $owner, int $limit = 5): Collection
    {
        // DiarySerializer::summary reads both counts; eager-load images_count too (comments alone left
        // the has-photos marker lazy-loading one query per row on the dashboard's my-diaries digest).
        $query = Diary::where('member_id', $owner->getKey())->with('member.avatar.file')->withCount(['comments', 'images']);
        DiaryVisibilityScope::apply($query, $viewer, $owner);

        return $query->orderByDesc('created_at')->limit($limit)->get();
    }
}
