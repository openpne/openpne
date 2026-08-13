<?php

namespace App\Features\Profile\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Features\Timeline\TimelineVisibilityScope;
use App\Models\Diary;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;

/**
 * The four headline counts on a member's profile digest, each scoped so the number never exceeds
 * what the viewer can actually open:
 * - diaries/activity reuse the shared visibility scopes, so they stay in lockstep with their
 *   previews (RecentMemberDiaries / MemberTimeline) instead of duplicating the clearance SQL;
 * - friends/groups carry no per-item visibility filter, matching ListFriends /
 *   ListMemberGroups. The owner→viewer block already 404s the whole page upstream.
 *
 * A count whose unit is switched off is zero and unqueried: the tile links into that unit, and this
 * query spans four of them, so each constraint lives here rather than at the call site
 * (feature-modules.md invariant 2).
 */
class ProfileStats
{
    /** @return array{diaries: int, activity: int, friends: int, groups: int} */
    public function __invoke(Member $viewer, Member $owner): array
    {
        return [
            'diaries' => Feature::Diary->enabled() ? $this->diaries($viewer, $owner) : 0,
            'activity' => Feature::Timeline->enabled() ? $this->activity($viewer, $owner) : 0,
            'friends' => Feature::Friend->enabled() ? $owner->friendships()->count() : 0,
            'groups' => Feature::Group->enabled()
                ? Group::whereHas('members', fn ($q) => $q->where('member_id', $owner->getKey()))->count()
                : 0,
        ];
    }

    private function diaries(Member $viewer, Member $owner): int
    {
        $diaries = Diary::where('member_id', $owner->getKey());
        DiaryVisibilityScope::apply($diaries, $viewer, $owner);

        return $diaries->count();
    }

    private function activity(Member $viewer, Member $owner): int
    {
        // Top-level posts only (replies excluded), matching MemberTimeline.
        $activity = TimelinePost::where('member_id', $owner->getKey())->whereNull('in_reply_to_id');
        TimelineVisibilityScope::apply($activity, $viewer, $owner);

        return $activity->count();
    }
}
