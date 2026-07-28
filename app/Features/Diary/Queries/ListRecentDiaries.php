<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The "Recently Posted Diaries" feed: every member's diaries
 * open to the membership at large, newest first — the web-public tier only for a guest
 * (DiaryVisibilityScope::applyFeed). The threshold is the all-members tier, so a friend's
 * Friends-only diary belongs to the friend feed, not here.
 *
 * Unlike OpenPNE 3, owners who block the viewer are excluded, keeping the feed consistent
 * with ShowDiary (which 404s a blocked viewer).
 */
class ListRecentDiaries
{
    /** @return LengthAwarePaginator<int, Diary> */
    public function __invoke(?Member $viewer, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($viewer)->paginate($perPage);
    }

    /**
     * First $limit diaries, unpaginated — for the home dashboard digest, which shows no pager and
     * must not read the host page's ?page=.
     *
     * @return Collection<int, Diary>
     */
    public function take(?Member $viewer, int $limit): Collection
    {
        return $this->query($viewer)->limit($limit)->get();
    }

    /** @return Builder<Diary> */
    private function query(?Member $viewer): Builder
    {
        $query = Diary::with('member.avatar.file')->withCount(['comments', 'images']);

        DiaryVisibilityScope::applyFeed($query, $viewer);

        return $query->orderByDesc('created_at');
    }
}
