<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineFeedScope;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The cross-member home feed: top-level posts the viewer may see — their own at every visibility,
 * anyone's web-public / all-members posts, and friends' friends-only posts — newest first. Replies
 * (in_reply_to_id set) are excluded, matching OpenPNE 3's timeline, which lists top-level activities.
 *
 * @return LengthAwarePaginator<int, TimelinePost>
 */
class HomeFeed
{
    /** @return LengthAwarePaginator<int, TimelinePost> */
    public function __invoke(Member $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $query = TimelinePost::query()
            ->whereNull('in_reply_to_id')
            ->with(['member', 'images.file']);

        TimelineFeedScope::apply($query, $viewer);

        // created_at is the human-meaningful order; id DESC is the stable tiebreaker for same-second
        // posts (and migrated rows sharing a timestamp), matching MemberTimeline.
        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }
}
