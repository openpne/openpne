<?php

namespace App\Features\Timeline\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * One member's timeline — their top-level posts under the viewer's clearance. Replies
 * (in_reply_to_id set) are excluded; they belong to a thread, not the member's stream, matching
 * OpenPNE 3's member timeline (opActivityQueryBuilder reads in_reply_to_activity_id IS NULL).
 * Same visibility/block rule as ListDiaries.
 *
 * @return LengthAwarePaginator<int, TimelinePost>
 */
class MemberTimeline
{
    /** @return LengthAwarePaginator<int, TimelinePost> */
    public function __invoke(Member $viewer, Member $owner, int $perPage = 20): LengthAwarePaginator
    {
        $query = TimelinePost::query()
            ->where('member_id', $owner->getKey())
            ->whereNull('in_reply_to_id')
            ->with(['member', 'images.file']);

        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('visibility', '<=', Visibility::clearanceFor($viewer, $owner)->value);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
