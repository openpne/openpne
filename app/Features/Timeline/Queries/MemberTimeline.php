<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineVisibilityScope;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Replies are excluded, matching OpenPNE 3's member timeline (opActivityQueryBuilder reads
 * in_reply_to_activity_id IS NULL).
 */
class MemberTimeline
{
    /** @return LengthAwarePaginator<int, TimelinePost> */
    public function __invoke(Member $viewer, Member $owner, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($viewer, $owner)->paginate($perPage);
    }

    /**
     * First $limit posts, unpaginated — for the profile timeline gadget, which shows no pager and
     * must not read the host page's ?page=.
     *
     * @return Collection<int, TimelinePost>
     */
    public function take(Member $viewer, Member $owner, int $limit): Collection
    {
        return $this->query($viewer, $owner)->limit($limit)->get();
    }

    /** @return Builder<TimelinePost> */
    private function query(Member $viewer, Member $owner): Builder
    {
        $query = TimelinePost::query()
            ->where('member_id', $owner->getKey())
            ->whereNull('in_reply_to_id')
            ->with(['member.avatar.file', 'images.file', 'linkCard.image', 'mentions', 'tags'])
            ->withCount('replies');

        TimelineVisibilityScope::apply($query, $viewer, $owner);

        // created_at is the human-meaningful order, with id DESC as the stable tiebreaker for
        // same-second and migrated rows (OpenPNE 3 opActivityQueryBuilder ordered by id alone).
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
