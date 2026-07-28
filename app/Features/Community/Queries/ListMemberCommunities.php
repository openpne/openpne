<?php

namespace App\Features\Community\Queries;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Communities a member has confirmed-joined. community_members
 * holds confirmed members only, so pending applications never leak in.
 */
class ListMemberCommunities
{
    public const PER_PAGE = 20;

    /**
     * The paged list carries `owner_is_admin` so the grid can crown the communities $member
     * administers — OpenPNE 3 crowns by the *listed* member's role, not the viewer's, so the
     * crown reads the same to everyone looking at that member's list. The unpaged take() path
     * (gadgets) draws no crown, so the projection stays here.
     *
     * @return LengthAwarePaginator<int, Community>
     */
    public function __invoke(Member $member, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->query($member)
            ->withExists(['members as owner_is_admin' => fn ($q) => $q
                ->where('member_id', $member->getKey())
                ->where('role', CommunityRole::Admin)])
            ->paginate($perPage);
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
            ->with(['category', 'image'])
            ->withCount('members')
            ->orderByDesc('id');
    }
}
