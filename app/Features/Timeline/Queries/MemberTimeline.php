<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineVisibilityScope;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Stream\StreamCursor;
use App\Support\Stream\StreamPage;
use App\Support\Stream\StreamQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Replies are excluded, matching OpenPNE 3's member timeline (opActivityQueryBuilder reads
 * in_reply_to_activity_id IS NULL).
 */
class MemberTimeline
{
    /** @return StreamPage<TimelinePost> */
    public function __invoke(Member $viewer, Member $owner, ?StreamCursor $before = null, int $perPage = RowsPage::DEFAULT): StreamPage
    {
        return StreamQuery::older($this->query($viewer, $owner), $before, $perPage);
    }

    /**
     * First $limit posts, unpaginated — for the profile timeline gadget, which must not read the host page's `?before=`.
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

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
