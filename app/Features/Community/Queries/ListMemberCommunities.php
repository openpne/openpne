<?php

namespace App\Features\Community\Queries;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Communities a member has confirmed-joined (OpenPNE 3 community/joinList). community_members
 * holds confirmed members only, so pending applications never leak in.
 */
class ListMemberCommunities
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Community> */
    public function __invoke(Member $member, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return Community::query()
            ->whereHas('members', fn ($q) => $q->where('member_id', $member->getKey()))
            ->withCount('members')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
