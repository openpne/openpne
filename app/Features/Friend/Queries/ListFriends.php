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
        return $this->query($viewer, $owner)->paginate($perPage);
    }

    /**
     * First $limit friends, unpaginated — for widgets (gadgets) that show no pager and must not
     * read the host page's ?page=.
     *
     * @return Collection<int, Member>
     */
    public function take(Member $viewer, Member $owner, int $limit): Collection
    {
        return $this->query($viewer, $owner)->limit($limit)->get();
    }

    /** @return BelongsToMany<Member, Member> */
    private function query(Member $viewer, Member $owner): BelongsToMany
    {
        // Both callers render the friend's avatar (Modern list rows, Classic FriendListBox gadget),
        // so eager-load it here to keep the row count from turning into an N+1.
        $query = $owner->friendships()->with('avatar.file');

        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
