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

    /**
     * The member's whole joined-community count, for a widget that shows a slice and links to the
     * rest — the take() slice can never stand in for it. One aggregate.
     */
    public function count(Member $member): int
    {
        return $this->query($member)->count();
    }

    /**
     * Every path carries `owner_is_admin` so any grid can crown the communities $member
     * administers — OpenPNE 3 crowns by the *listed* member's role, not the viewer's, so the crown
     * reads the same to everyone looking at that member's list. It is one correlated EXISTS in the
     * select list, not a query per row, so the slice paths pay it at the same order as the page.
     *
     * @return Builder<Community>
     */
    private function query(Member $member): Builder
    {
        return Community::query()
            ->whereHas('members', fn ($q) => $q->where('member_id', $member->getKey()))
            ->with(['category', 'image'])
            ->withCount('members')
            ->withExists(['members as owner_is_admin' => fn ($q) => $q
                ->where('member_id', $member->getKey())
                ->where('role', CommunityRole::Admin)])
            ->orderByDesc('id');
    }
}
