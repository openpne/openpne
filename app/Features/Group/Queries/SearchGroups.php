<?php

namespace App\Features\Group\Queries;

use App\Models\Group;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * OpenPNE 3 community search: by name keyword and/or category. An empty keyword applies no name
 * filter (browse, optionally narrowed by category).
 *
 * Wildcards in the keyword are not escaped, matching SearchDiaries / SearchMembers; the term is
 * still bound, so this is wildcard latitude, not injection.
 */
class SearchGroups
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Group> */
    public function __invoke(string $keyword = '', ?int $categoryId = null, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $keyword = trim($keyword);

        return Group::query()
            ->when($keyword !== '', fn ($q) => $q->where('name', 'like', '%'.$keyword.'%'))
            ->when($categoryId !== null, fn ($q) => $q->where('group_category_id', $categoryId))
            ->with(['category', 'image'])
            ->withCount('members')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
