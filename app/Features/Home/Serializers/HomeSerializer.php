<?php

namespace App\Features\Home\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\TimelinePost;
use Illuminate\Support\Collection;

/**
 * The Modern home dashboard: a capped digest of the member-facing feeds, each shaped by its own
 * feature serializer. The all-members diary feed is the primary; the friend feed is one tap away
 * (its header link), so the two near-identical feeds of a small SNS never sit side by side. These
 * are previews (no pager); each section's "View all" deep-links to the full list.
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
     * @return array{announcements: array, diaries: list<array>, timeline: list<array>, groupActivity: list<array>, myDiaries: list<array>}
     */
    public static function dashboard(
        Collection $diaries,
        Collection $timeline,
        Collection $groupActivity,
        Collection $myDiaries,
        array $unread,
        Collection $pendingApprovals,
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
            'diaries' => $diaries->map([DiarySerializer::class, 'summary'])->all(),
            'timeline' => $timeline->map([TimelinePostSerializer::class, 'entry'])->all(),
            'groupActivity' => $groupActivity->map([self::class, 'activityEntry'])->all(),
            'myDiaries' => $myDiaries->map([DiarySerializer::class, 'summary'])->all(),
        ];
    }

    /**
     * One group activity row — a topic or an event — flattened for the digest: the group it
     * belongs to (the byline subject, so its image is here too), its comment count, and (events
     * only) its participant count. `kind` drives the client's byline note and its link target. Callers
     * eager-load the owning group's image.
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
