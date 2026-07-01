<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineFeedScope;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The cross-member home feed: top-level posts the viewer may see — their own at every visibility,
 * anyone's web-public / all-members posts, and friends' friends-only posts — newest first. Replies
 * (in_reply_to_id set) are excluded, matching OpenPNE 3's timeline, which lists top-level activities.
 */
class HomeFeed
{
    /** @return LengthAwarePaginator<int, TimelinePost> */
    public function __invoke(Member $viewer, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($viewer)->paginate($perPage);
    }

    /**
     * First $limit posts, unpaginated — for the home dashboard digest, which shows no pager and
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
            ->with(['member', 'images.file']);

        TimelineFeedScope::apply($query, $viewer);

        // created_at is the human-meaningful order; id DESC is the stable tiebreaker for same-second
        // posts (and migrated rows sharing a timestamp), matching MemberTimeline.
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
