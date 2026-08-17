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
        // The paged grid labels each row "name (friend count)"; a subquery keeps that one query for
        // the whole page. take() stays uncounted — its gadgets print the bare name. withQueryString
        // keeps the ?id= subject on pager links — without it, page 2 of another member's list
        // silently becomes the viewer's own.
        return $this->query($viewer, $owner)->withCount('friendships')->paginate($perPage)->withQueryString();
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

    /**
     * The $limit newest friendships, newest first — for a decorative row of faces, where a new friend
     * arriving at the front is a rule its own member can read off the screen, rather than the order the
     * table happens to hand back. It is the row's own order and not the friend list's: the paged roster
     * behind it is unordered, as it has always been.
     *
     * Both keys are the pivot's, so the sort finishes on `friendships` and the join runs for the $limit
     * rows that survive it. Ordering by `members.id` instead selects the same rows in the same order —
     * the join is on that column — but sorts after the join, which costs a row lookup per friendship the
     * member has.
     *
     * Separate from take() rather than an order on it: the gadget and the profile grid print rows as
     * the table returns them, and an ORDER BY reaching them would change screens that are shipped.
     *
     * @return Collection<int, Member>
     */
    public function takeNewest(Member $viewer, Member $owner, int $limit): Collection
    {
        return $this->query($viewer, $owner)
            ->orderByPivot('created_at', 'desc')
            ->orderByPivot('friend_id', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * The owner's whole friend count, for a widget that shows a slice and links to the rest — the
     * take() slice can never stand in for it. One aggregate, so the same block rule applies as to
     * the list itself.
     */
    public function count(Member $viewer, Member $owner): int
    {
        return $this->query($viewer, $owner)->count();
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
