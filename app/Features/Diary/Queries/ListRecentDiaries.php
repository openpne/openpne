<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Stream\StreamCursor;
use App\Support\Stream\StreamPage;
use App\Support\Stream\StreamQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The "Recently Posted Diaries" feed, newest first — the web-public tier only for a guest
 * ({@see DiaryVisibilityScope::applyFeed}). Unlike OpenPNE 3, owners who block the viewer are
 * excluded, keeping the feed consistent with ShowDiary.
 */
class ListRecentDiaries
{
    public const PER_PAGE = 20;

    /** @return StreamPage<Diary> */
    public function __invoke(?Member $viewer, ?StreamCursor $before = null, int $perPage = self::PER_PAGE): StreamPage
    {
        return StreamQuery::older($this->query($viewer), $before, $perPage);
    }

    /**
     * First $limit diaries, unpaginated — for the home dashboard digest, which must not read the host page's `?before=`.
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

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
