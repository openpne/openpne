<?php

namespace App\Features\Community\Queries;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Members with a pending join request for a community, oldest first (the admin approval queue).
 * Reads community_join_requests via the applicants() pivot.
 */
class ListPendingMembers
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(Community $community, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $community->applicants()
            ->orderByPivot('created_at')
            ->paginate($perPage);
    }
}
