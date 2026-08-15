<?php

namespace App\Features\Group\Queries;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Groups a member has confirmed-joined. group_members
 * holds confirmed members only, so pending applications never leak in.
 */
class ListMemberGroups
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Group> */
    public function __invoke(Member $member, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->query($member)->paginate($perPage);
    }

    /**
     * First $limit groups, unpaginated — for widgets (gadgets) that show no pager and must
     * not read the host page's ?page=.
     *
     * @return Collection<int, Group>
     */
    public function take(Member $member, int $limit): Collection
    {
        return $this->query($member)->limit($limit)->get();
    }

    /**
     * Every group $member has joined, unpaginated — for a management screen that must account for
     * all of them, where a slice would quietly hide a seat the operator came to give up.
     *
     * @return Collection<int, Group>
     */
    public function all(Member $member): Collection
    {
        return $this->query($member)->get();
    }

    /**
     * The member's whole joined-group count, for a widget that shows a slice and links to the
     * rest — the take() slice can never stand in for it. One aggregate.
     */
    public function count(Member $member): int
    {
        return $this->query($member)->count();
    }

    /**
     * Every path carries `owner_is_admin` so any grid can crown the groups $member
     * administers — OpenPNE 3 crowns by the *listed* member's role, not the viewer's, so the crown
     * reads the same to everyone looking at that member's list. It is one correlated EXISTS in the
     * select list, not a query per row, so the slice paths pay it at the same order as the page.
     *
     * @return Builder<Group>
     */
    private function query(Member $member): Builder
    {
        return Group::query()
            ->whereHas('members', fn ($q) => $q->where('member_id', $member->getKey()))
            ->with(['category', 'image'])
            ->withCount('members')
            ->withExists(['members as owner_is_admin' => fn ($q) => $q
                ->where('member_id', $member->getKey())
                ->where('role', GroupRole::Admin)])
            ->orderByDesc('id');
    }
}
