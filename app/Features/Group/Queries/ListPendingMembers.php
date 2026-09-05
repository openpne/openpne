<?php

namespace App\Features\Group\Queries;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
