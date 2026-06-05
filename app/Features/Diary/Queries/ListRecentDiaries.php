<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * OpenPNE 3 diary "Recently Posted Diaries" feed (action `list`): every member's diaries
 * open to the membership at large, newest first. The threshold is fixed at Members — the
 * feed is the all-members tier, so a friend's Friends-only diary belongs to the friend feed,
 * not here. Open (web-public) sits below Members on the monotonic scale, so it is included.
 *
 * Unlike OpenPNE 3, owners who block the viewer are excluded, keeping the feed consistent
 * with ShowDiary (which 404s a blocked viewer).
 */
class ListRecentDiaries
{
    /** @return LengthAwarePaginator<int, Diary> */
    public function __invoke(Member $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $query = Diary::with('member.avatar.file')->withCount('comments')
            ->where('visibility', '<=', Visibility::Members->value);

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'diaries.member_id');

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
