<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\ArchivePeriod;
use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListDiaries
{
    /**
     * A member's diary archive under the viewer's clearance, narrowed by a calendar period and a
     * keyword independently. `$period` and `$keyword` follow `$perPage` so the original positional
     * signature stays compatible.
     *
     * @return LengthAwarePaginator<int, Diary>
     */
    public function __invoke(?Member $viewer, Member $owner, int $perPage = 20, ?ArchivePeriod $period = null, string $keyword = ''): LengthAwarePaginator
    {
        $query = Diary::query()->where('member_id', $owner->getKey())
            ->with('member.avatar.file')->withCount(['comments', 'images']);

        DiaryVisibilityScope::apply($query, $viewer, $owner);

        if ($period !== null) {
            $query->where('created_at', '>=', $period->start)->where('created_at', '<', $period->end);
        }

        SearchDiaries::applyTerms($query, $keyword);

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
