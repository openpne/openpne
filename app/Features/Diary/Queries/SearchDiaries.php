<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\DiarySearch;
use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Database\SearchCollation;
use App\Support\SearchInput;
use App\Support\SearchInputRejected;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Diary keyword search over the recent feed's tier: each whitespace-split term must match the title
 * or the body, AND-connected.
 */
class SearchDiaries
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Diary> */
    public function __invoke(?Member $viewer, string $keyword, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $query = Diary::with('member.avatar.file')->withCount(['comments', 'pictures']);

        DiaryVisibilityScope::applyFeed($query, $viewer);

        self::applyTerms($query, $keyword);

        // The window is this screen's alone: applyTerms is shared with the member archive, which OpenPNE 3 never narrowed.
        $query->when(DiarySearch::periodLowerBound(), fn ($q, $lower) => $q->where('created_at', '>=', $lower));

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    /**
     * Shared with the member archive so a keyword scans title and body identically wherever it
     * applies. An empty keyword is a no-op.
     *
     * @param  Builder  $query  a diary query builder (Eloquent builder or a Diary relation)
     */
    public static function applyTerms(Builder $query, string $keyword): void
    {
        foreach (self::terms($keyword) as $term) {
            $query->where(function ($q) use ($term): void {
                SearchCollation::whereContains($q, 'title', $term);
                SearchCollation::orWhereContains($q, 'body', $term);
            });
        }
    }

    /**
     * Split a raw keyword into terms the way OpenPNE 3 did: treat a full-width space as a
     * separator, then split on whitespace and drop empties. An empty result is OpenPNE 3's
     * `forwardUnless($keywords, 'diary', 'list')` condition — the caller renders the list.
     *
     * @return list<string>
     *
     * @throws SearchInputRejected
     */
    public static function terms(string $keyword): array
    {
        $normalized = trim(str_replace('　', ' ', $keyword));
        SearchInput::ensureLength($normalized);

        $terms = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        SearchInput::ensureTermCount($terms);

        return $terms;
    }
}
