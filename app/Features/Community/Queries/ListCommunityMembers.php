<?php

namespace App\Features\Community\Queries;

use App\Models\Community;
use App\Models\CommunityMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Confirmed members of a community, admins first. No block
 * filtering — a community member list is a many-member set, and blocks are 1:1 (accepted gap).
 */
class ListCommunityMembers
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, CommunityMember> */
    public function __invoke(Community $community, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $community->members()
            // withCount rides the eager load so the grid's "name (friend count)" stays one subquery
            // for the page rather than one query per row.
            ->with(['member' => fn ($q) => $q->with('avatar.file')->withCount('friendships')])
            ->orderByDesc('role') // Admin=3 > SubAdmin=2 > Member=1
            ->orderBy('id')
            ->paginate($perPage);
    }
}
