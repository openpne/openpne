<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * OpenPNE 3 friend diary feed (action `listFriend`): diaries by the viewer's friends, newest
 * first. The threshold is Friends — a friend's Friends/Members/Open diaries all qualify, their
 * Private ones do not. No friends means an empty feed (whereIn on an empty set yields no rows).
 *
 * Block exclusion mirrors ListRecentDiaries / ShowDiary for the edge case of a friend who has
 * since blocked the viewer.
 */
class ListFriendDiaries
{
    /** @return LengthAwarePaginator<int, Diary> */
    public function __invoke(Member $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $friendIds = $viewer->friendships()->pluck('members.id');

        $query = Diary::with('member')->withCount('comments')
            ->whereIn('member_id', $friendIds)
            ->where('visibility', '<=', Visibility::Friends->value);

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'diaries.member_id');

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
