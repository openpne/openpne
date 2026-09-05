<?php

namespace App\Features\Group\Queries;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
     * Deliberately unpaginated: a management screen must account for every seat, and a slice would
     * hide one the operator came to give up.
     *
     * @return Collection<int, Group>
     */
    public function all(Member $member): Collection
    {
        return $this->query($member)->get();
    }

    public function count(Member $member): int
    {
        return $this->query($member)->count();
    }

    /**
     * Every path carries `owner_is_admin`, and OpenPNE 3 crowns by the *listed* member's role, not
     * the viewer's. It is one correlated EXISTS in the select list, not a query per row.
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
