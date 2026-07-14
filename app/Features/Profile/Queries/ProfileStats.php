<?php

namespace App\Features\Profile\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Features\Timeline\TimelineVisibilityScope;
use App\Models\Community;
use App\Models\Diary;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * The four headline counts on a member's profile digest, each scoped so the number never exceeds
 * what the viewer can actually open:
 * - diaries/activity reuse the shared visibility scopes, so they stay in lockstep with their
 *   previews (RecentMemberDiaries / MemberTimeline) instead of duplicating the clearance SQL;
 * - friends/communities carry no per-item visibility filter, matching ListFriends /
 *   ListMemberCommunities. The owner→viewer block already 404s the whole page upstream.
 */
class ProfileStats
{
    /** @return array{diaries: int, activity: int, friends: int, communities: int} */
    public function __invoke(Member $viewer, Member $owner): array
    {
        $diaries = Diary::where('member_id', $owner->getKey());
        DiaryVisibilityScope::apply($diaries, $viewer, $owner);

        // Top-level posts only (replies excluded), matching MemberTimeline.
        $activity = TimelinePost::where('member_id', $owner->getKey())->whereNull('in_reply_to_id');
        TimelineVisibilityScope::apply($activity, $viewer, $owner);

        return [
            'diaries' => $diaries->count(),
            'activity' => $activity->count(),
            'friends' => $owner->friendships()->count(),
            'communities' => Community::whereHas('members', fn ($q) => $q->where('member_id', $owner->getKey()))->count(),
        ];
    }
}
