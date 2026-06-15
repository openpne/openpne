<?php

namespace App\Features\Community\Queries;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        return $this->query($member)->paginate($perPage);
    }

    /**
     * First $limit communities, unpaginated — for widgets (gadgets) that show no pager and must
     * not read the host page's ?page=.
     *
     * @return Collection<int, Community>
     */
    public function take(Member $member, int $limit): Collection
    {
        return $this->query($member)->limit($limit)->get();
    }

    /** @return Builder<Community> */
    private function query(Member $member): Builder
    {
        return Community::query()
            ->whereHas('members', fn ($q) => $q->where('member_id', $member->getKey()))
            ->withCount('members')
            ->orderByDesc('id');
    }
}
