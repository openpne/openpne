<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineFeedScope;
use App\Models\Community;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One community's timeline: its top-level posts, newest first. The counterpart of HomeFeed for the
 * single scope the SNS-wide feeds exclude — whether this viewer may open it at all is
 * CommunityTimelineAccess's question, asked by the caller before this runs.
 */
class CommunityTimeline
{
    /** @return LengthAwarePaginator<int, TimelinePost> */
    public function __invoke(Member $viewer, Community $community, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($viewer, $community)->paginate($perPage);
    }

    /**
     * First $limit posts, unpaginated — for the community home box, which shows no pager and must
     * not read the host page's ?page=.
     *
     * @return Collection<int, TimelinePost>
     */
    public function take(Member $viewer, Community $community, int $limit): Collection
    {
        return $this->query($viewer, $community)->limit($limit)->get();
    }

    /** @return Builder<TimelinePost> */
    private function query(Member $viewer, Community $community): Builder
    {
        $query = TimelinePost::query()
            ->whereNull('in_reply_to_id')
            ->with(['member.avatar.file', 'images.file', 'linkCard.image', 'mentions', 'tags'])
            ->withCount('replies');

        TimelineFeedScope::applyCommunity($query, $viewer, $community);

        // Same order as every other feed: created_at is the human-meaningful key, id DESC the
        // stable tiebreaker for same-second posts and for migrated rows sharing a timestamp.
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
