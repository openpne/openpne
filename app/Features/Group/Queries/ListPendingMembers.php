<?php

namespace App\Features\Group\Queries;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Members with a pending join request for a group, oldest first (the admin approval queue).
 * Reads group_join_requests via the applicants() pivot.
 */
class ListPendingMembers
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(Group $group, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $group->applicants()
            ->with('avatar.file')
            ->orderByPivot('created_at')
            ->paginate($perPage);
    }
}
