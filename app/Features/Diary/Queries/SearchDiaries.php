<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Diary keyword search over the same tier the recent feed exposes
 * (DiaryVisibilityScope::applyFeed). Each whitespace-split term must match the title or body;
 * terms are AND-connected. An empty keyword applies no term filter, so the page shows recent
 * diaries — OpenPNE 3 forwarded an empty search to `list`.
 *
 * Wildcards are not escaped, matching the existing member search (SearchMembers); the term is
 * still bound, so this is wildcard latitude, not injection.
 */
class SearchDiaries
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Diary> */
    public function __invoke(?Member $viewer, string $keyword, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $query = Diary::with('member.avatar.file')->withCount(['comments', 'images']);

        DiaryVisibilityScope::applyFeed($query, $viewer);

        self::applyTerms($query, $keyword);

        return $query->orderByDesc('created_at')->paginate($perPage)->withQueryString();
    }

    /**
     * AND-connect each whitespace-split term as a title/body LIKE onto an existing diary query.
     * Shared with the member archive ({@see ListDiaries}, {@see MemberDiaryMonthlyCounts}) so a
     * keyword scans title and body identically wherever it applies. Empty keyword → no-op.
     *
     * @param  Builder  $query  a diary query builder (Eloquent builder or a Diary relation)
     */
    public static function applyTerms(Builder $query, string $keyword): void
    {
        foreach (self::terms($keyword) as $term) {
            $query->where(fn ($q) => $q
                ->where('title', 'like', '%'.$term.'%')
                ->orWhere('body', 'like', '%'.$term.'%'));
        }
    }

    /**
     * Split a raw keyword into terms the way OpenPNE 3 did: treat a full-width space as a
     * separator, then split on whitespace and drop empties. An empty result is OpenPNE 3's
     * `forwardUnless($keywords, 'diary', 'list')` condition — the caller renders the list.
     *
     * @return list<string>
     */
    public static function terms(string $keyword): array
    {
        $normalized = str_replace('　', ' ', $keyword);

        return preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
