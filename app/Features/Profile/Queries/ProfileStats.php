<?php

namespace App\Features\Profile\Queries;

use App\Features\Diary\DiaryVisibilityScope;
use App\Features\Timeline\TimelineVisibilityScope;
use App\Models\Diary;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;

/** Diaries and activity reuse their previews' visibility scopes, so a count never exceeds what the viewer can open. */
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
