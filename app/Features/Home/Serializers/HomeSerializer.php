<?php

namespace App\Features\Home\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\GroupTalk\Serializers\TalkRoomSerializer;
use App\Features\GroupTalk\TalkRoom;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Support\Collection;

/**
 * The Modern home dashboard: a capped digest of the member-facing feeds, each shaped by its own
 * feature serializer. The all-members diary feed is the primary and the friend feed one tap away, so
 * the two near-identical feeds of a small SNS never sit side by side.
 */
class HomeSerializer
{
    /**
     * @param  Collection<int, Diary>  $diaries  all-members recent diaries
     * @param  Collection<int, TimelinePost>  $timeline  home timeline feed
     * @param  Collection<int, GroupTopic|GroupEvent>  $groupActivity  joined-community activity
     * @param  Collection<int, Diary>  $myDiaries  the viewer's own recent diaries
     * @param  array{friendRequests: int, unreadMessages: int}  $unread  shell attention counts
     * @param  Collection<int, Group>  $pendingApprovals  admin groups with applicants_count
     * @param  Collection<int, TalkRoom>  $talkRooms  the viewer's conversations, most recent first
     * @return array{announcements: array, talkRooms: list<array>, diaries: list<array>, timeline: list<array>, groupActivity: list<array>, myDiaries: list<array>}
     */
    public static function dashboard(
        Member $viewer,
        Collection $diaries,
        Collection $timeline,
        Collection $groupActivity,
        Collection $myDiaries,
        array $unread,
        Collection $pendingApprovals,
        Collection $talkRooms,
    ): array {
        return [
            'announcements' => [
                'friendRequests' => $unread['friendRequests'],
                'unreadMessages' => $unread['unreadMessages'],
                'groupApprovals' => $pendingApprovals->map(fn (Group $c): array => [
                    'groupId' => $c->getKey(),
                    'groupName' => $c->name,
                    'count' => $c->applicants_count,
                ])->all(),
            ],
            // Rows only: the digest leads the screen and its "View all" is the room list itself,
            // so there is no pager here to feed.
            'talkRooms' => $talkRooms->map([TalkRoomSerializer::class, 'room'])->all(),
            'diaries' => $diaries->map([DiarySerializer::class, 'summary'])->all(),
            'timeline' => $timeline->map(fn (TimelinePost $post): array => TimelinePostSerializer::entry($post, $viewer))->all(),
            'groupActivity' => $groupActivity->map([self::class, 'activityEntry'])->all(),
            'myDiaries' => $myDiaries->map([DiarySerializer::class, 'summary'])->all(),
        ];
    }

    /**
     * One group activity row — a topic or an event — flattened for the digest, with `kind` driving
     * the client's byline note and its link target. Callers eager-load the owning group's image.
     *
     * @return array{kind: 'topic'|'event', id: int, name: string, commentCount: int, participantCount: int|null, group: array{id: int, name: string, imageUrl: string|null}, updatedAt: string}
     */
    public static function activityEntry(GroupTopic|GroupEvent $row): array
    {
        return [
            'kind' => $row instanceof GroupTopic ? 'topic' : 'event',
            'id' => $row->getKey(),
            'name' => $row->name,
            'commentCount' => $row->comments_count ?? 0,
            // Topics have no roster; only events carry a participant count (null suppresses the badge).
            'participantCount' => $row instanceof GroupEvent ? ($row->participants_count ?? 0) : null,
            'group' => [
                'id' => $row->group->getKey(),
                'name' => $row->group->name,
                // Byline-avatar size, matching DiarySerializer's author image — not the larger
                // group tile GroupSerializer serves.
                'imageUrl' => $row->group->image?->thumbnailUrl(120, 120, square: true),
            ],
            'updatedAt' => $row->updated_at->toIso8601String(),
        ];
    }
}
