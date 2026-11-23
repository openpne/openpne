<?php

namespace App\Features\Group\Queries;

use App\Models\Group;
use App\Support\Database\SearchCollation;
use App\Support\SearchInput;
use App\Support\SearchInputRejected;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** An empty keyword applies no name filter. */
class SearchGroups
{
    public const PER_PAGE = 20;

    /**
     * @return LengthAwarePaginator<int, Group>
     *
     * @throws SearchInputRejected
     */
    public function __invoke(string $keyword = '', ?int $categoryId = null, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $keyword = trim($keyword);
        SearchInput::ensureLength($keyword);

        return Group::query()
            ->when($keyword !== '', fn ($q) => SearchCollation::whereContains($q, 'name', $keyword))
            ->when($categoryId !== null, fn ($q) => $q->where('group_category_id', $categoryId))
            ->with(['category', 'image'])
            ->withCount('members')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
