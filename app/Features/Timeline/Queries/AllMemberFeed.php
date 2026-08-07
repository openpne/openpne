<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineFeedScope;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The whole SNS's members-only timeline: top-level posts every member may see (visibility <= Members),
 * newest first — matching OpenPNE 3's getAllMemberActivityList. Narrower than HomeFeed: it adds no
 * viewer-specific tiers, so the viewer's own Private posts and a friend's friends-only posts are not
 * included. Replies (in_reply_to_id set) are excluded, matching the other timeline feeds.
 */
class AllMemberFeed
{
    /** @return LengthAwarePaginator<int, TimelinePost> */
    public function __invoke(Member $viewer, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($viewer)->paginate($perPage);
    }

    /**
     * First $limit posts, unpaginated — for the allMemberActivityBox gadget, which shows no pager and
     * must not read the host page's ?page=.
     *
     * @return Collection<int, TimelinePost>
     */
    public function take(Member $viewer, int $limit): Collection
    {
        return $this->query($viewer)->limit($limit)->get();
    }

    /** @return Builder<TimelinePost> */
    private function query(Member $viewer): Builder
    {
        $query = TimelinePost::query()
            ->whereNull('in_reply_to_id')
            ->with(['member.avatar.file', 'images.file', 'linkCard.image'])
            ->withCount('replies');

        TimelineFeedScope::applyMembersOnly($query, $viewer);

        // created_at is the human-meaningful order; id DESC is the stable tiebreaker for same-second
        // posts (and migrated rows sharing a timestamp), matching the other timeline feeds.
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
