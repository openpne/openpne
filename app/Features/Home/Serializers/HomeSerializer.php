<?php

namespace App\Features\Home\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The Modern home dashboard: a capped digest of the member-facing feeds, each shaped by its own
 * feature serializer: the diary lists and the group activity in the OpenPNE 3 default home's order,
 * and the timeline last by choice, where OpenPNE 3 put it ahead of the group activity.
 */
class HomeSerializer
{
    /**
     * @param  Collection<int, Diary>  $friendDiaries  recent diaries by the viewer's friends
     * @param  Collection<int, Diary>  $diaries  all-members recent diaries
     * @param  Collection<int, Diary>  $commentedDiaries  diaries the viewer commented on, last comment first
     * @param  Collection<int, TimelinePost>  $timeline  home timeline feed
     * @param  Collection<int, GroupTopic|GroupEvent>  $groupActivity  joined-community activity
     * @param  Collection<int, Diary>  $myDiaries  the viewer's own recent diaries
     * @param  array{friendRequests: int, unreadMessages: int}  $unread  shell attention counts
     * @param  Collection<int, Group>  $pendingApprovals  admin groups with applicants_count
     * @param  Collection<int, Group>  $adminTransfers  groups whose administration the viewer is asked to take over
     * @param  bool  $newcomer  the viewer has no friend and has joined no group
     * @param  array<int, list<array{emoji: string, count: int, mine: bool}>>  $timelineReactions  chips keyed by post id
     * @return array{announcements: array, newcomer: bool, friendDiaries: list<array>, diaries: list<array>, commentedDiaries: list<array>, timeline: list<array>, groupActivity: list<array>, myDiaries: list<array>}
     */
    public static function dashboard(
        Member $viewer,
        Collection $friendDiaries,
        Collection $diaries,
        Collection $commentedDiaries,
        Collection $timeline,
        Collection $groupActivity,
        Collection $myDiaries,
        array $unread,
        Collection $pendingApprovals,
        Collection $adminTransfers,
        bool $newcomer,
        array $timelineReactions,
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
                'adminTransfers' => $adminTransfers->map(fn (Group $group): array => [
                    'groupId' => $group->getKey(),
                    'groupName' => $group->name,
                ])->all(),
            ],
            'newcomer' => $newcomer,
            'friendDiaries' => $friendDiaries->map([DiarySerializer::class, 'summary'])->all(),
            'diaries' => $diaries->map([DiarySerializer::class, 'summary'])->all(),
            'commentedDiaries' => $commentedDiaries->map([DiarySerializer::class, 'commented'])->all(),
            'timeline' => $timeline->map(fn (TimelinePost $post): array => TimelinePostSerializer::entry($post, $viewer, $timelineReactions[$post->getKey()] ?? []))->all(),
            'groupActivity' => $groupActivity->map([self::class, 'activityEntry'])->all(),
            'myDiaries' => $myDiaries->map([DiarySerializer::class, 'summary'])->all(),
        ];
    }

    /**
     * One group activity row — a topic or an event — flattened for the digest, with `kind` driving
     * the client's byline note and its link target. Callers eager-load the owning group's image.
     *
     * @return array{kind: 'topic'|'event', id: int, name: string, commentCount: int, participantCount: int|null, openDate: string|null, group: array{id: int, name: string, imageUrl: string|null}, bumpedAt: string}
     */
    public static function activityEntry(GroupTopic|GroupEvent $row): array
    {
        return [
            'kind' => $row instanceof GroupTopic ? 'topic' : 'event',
            'id' => $row->getKey(),
            'name' => $row->name,
            'commentCount' => $row->comments_count ?? $row->loadCount('comments')->comments_count,
            // Topics have no roster; only events carry a participant count (null suppresses the badge).
            'participantCount' => $row instanceof GroupEvent ? ($row->participants_count ?? 0) : null,
            'openDate' => $row instanceof GroupEvent ? $row->open_date->format('Y-m-d') : null,
            'group' => [
                'id' => $row->group->getKey(),
                'name' => $row->group->name,
                // Byline-avatar size, matching DiarySerializer's author image — not the larger
                // group tile GroupSerializer serves.
                'imageUrl' => $row->group->image?->thumbnailUrl(120, 120, square: true),
            ],
            'bumpedAt' => $row->bumped_at->toIso8601String(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, GroupTopic|GroupEvent>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function activityPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'activityEntry'], $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
