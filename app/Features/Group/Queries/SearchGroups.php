<?php

namespace App\Features\Group\Queries;

use App\Models\Group;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * An empty keyword applies no name filter. Wildcards in the keyword are not escaped, as in the
 * diary and member searches; the term is still bound, so this is wildcard latitude, not injection.
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
