<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * OpenPNE 3 diary search (action `search`): keyword search over the same all-member tier the
 * recent feed exposes (visibility <= Members, blocking owners excluded). Each whitespace-split
 * term must match the title or body; terms are AND-connected. An empty keyword applies no term
 * filter, so the page shows recent diaries — OpenPNE 3 forwarded an empty search to `list`.
 *
 * Wildcards are not escaped, matching the existing member search (SearchMembers); the term is
 * still bound, so this is wildcard latitude, not injection.
 */
class SearchDiaries
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Diary> */
    public function __invoke(Member $viewer, string $keyword, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $query = Diary::with('member')->where('visibility', '<=', Visibility::Members->value);

        BlockLookup::excludeOwnersBlockingViewer($query, $viewer, 'diaries.member_id');

        foreach (self::terms($keyword) as $term) {
            $query->where(fn ($q) => $q
                ->where('title', 'like', '%'.$term.'%')
                ->orWhere('body', 'like', '%'.$term.'%'));
        }

        return $query->orderByDesc('created_at')->paginate($perPage)->withQueryString();
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
