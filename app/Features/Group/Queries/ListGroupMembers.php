<?php

namespace App\Features\Group\Queries;

use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** No block filtering — a group member list is a many-member set and blocks are 1:1 (accepted gap). */
class ListGroupMembers
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, GroupMember> */
    public function __invoke(Group $group, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $group->members()
            // withCount rides the eager load so the grid's "name (friend count)" stays one subquery
            // for the page rather than one query per row.
            ->with(['member' => fn ($q) => $q->with('avatar.file')->withCount('friendships')])
            ->orderByDesc('role') // Admin=3 > SubAdmin=2 > Member=1
            ->orderBy('id')
            // withQueryString keeps the ?id= subject on pager links — without it, page 2 resolves
            // group id 0 and 404s.
            ->paginate($perPage)
            ->withQueryString();
    }
}
