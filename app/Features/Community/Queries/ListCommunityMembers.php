<?php

namespace App\Features\Community\Queries;

use App\Models\Community;
use App\Models\CommunityMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Confirmed members of a community (OpenPNE 3 community/memberList), admins first. No block
 * filtering — a community member list is a many-member set, and blocks are 1:1 (accepted gap).
 */
class ListCommunityMembers
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, CommunityMember> */
    public function __invoke(Community $community, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $community->members()
            ->with('member')
            ->orderByDesc('role') // Admin=3 > SubAdmin=2 > Member=1
            ->orderBy('id')
            ->paginate($perPage);
    }
}
