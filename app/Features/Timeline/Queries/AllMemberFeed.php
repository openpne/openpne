<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineFeedScope;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Stream\StreamCursor;
use App\Support\Stream\StreamPage;
use App\Support\Stream\StreamQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Top-level posts every member may see, newest first (OpenPNE 3 getAllMemberActivityList). Narrower
 * than HomeFeed: no viewer-specific tiers, so the viewer's own Private posts and a friend's
 * friends-only ones stay out.
 */
class AllMemberFeed
{
    /** @return StreamPage<TimelinePost> */
    public function __invoke(Member $viewer, ?StreamCursor $before = null, int $perPage = RowsPage::DEFAULT): StreamPage
    {
        return StreamQuery::older($this->query($viewer), $before, $perPage);
    }

    /**
     * First $limit posts, unpaginated — for the allMemberActivityBox gadget, which must not read the host page's `?before=`.
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
            ->with(['member.avatar.file', 'images.file', 'linkCard.image', 'mentions', 'tags'])
            ->withCount('replies');

        TimelineFeedScope::applyMembersOnly($query, $viewer);

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
