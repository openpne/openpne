<?php

namespace App\Features\Friend\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class ListFriends
{
    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(Member $viewer, Member $owner, int $perPage = 20): LengthAwarePaginator
    {
        // Without withQueryString(), page 2 of another member's list silently becomes the viewer's own.
        return $this->query($viewer, $owner)->withCount('friendships')->paginate($perPage)->withQueryString();
    }

    /**
     * Unpaginated, so a gadget on a paged page does not read that page's `?page=`.
     *
     * @return Collection<int, Member>
     */
    public function take(Member $viewer, Member $owner, int $limit): Collection
    {
        return $this->query($viewer, $owner)->limit($limit)->get();
    }

    public function count(Member $viewer, Member $owner): int
    {
        return $this->query($viewer, $owner)->count();
    }

    /** @return BelongsToMany<Member, Member> */
    private function query(Member $viewer, Member $owner): BelongsToMany
    {
        // Both keys are the pivot's, so the sort finishes on `friendships` before the member join.
        $query = $owner->friendships()->with('avatar.file')
            ->orderByPivot('created_at', 'desc')
            ->orderByPivot('friend_id', 'desc');

        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
