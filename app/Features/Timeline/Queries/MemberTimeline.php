<?php

namespace App\Features\Timeline\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One member's timeline — their top-level posts under the viewer's clearance. Replies
 * (in_reply_to_id set) are excluded, matching OpenPNE 3's member timeline (opActivityQueryBuilder
 * reads in_reply_to_activity_id IS NULL).
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
            ->with(['member.avatar.file', 'images.file', 'linkCard.image', 'mentions'])
            ->withCount('replies');

        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('visibility', '<=', Visibility::clearanceFor($viewer, $owner)->value);
        }

        // OpenPNE 3 opActivityQueryBuilder orders by id DESC. Keep created_at as the primary key
        // for human-meaningful order, with id DESC as the stable tiebreaker for same-second posts
        // (and migrated rows sharing a timestamp).
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
