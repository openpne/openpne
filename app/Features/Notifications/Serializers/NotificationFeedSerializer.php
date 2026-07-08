<?php

namespace App\Features\Notifications\Serializers;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\Diary\DiaryAccess;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\Member;
use App\Models\MessageRecipient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Modern feed shapes for the per-event notification rows (layer 3). Rows store only the kind
 * discriminator and entity ids; everything displayed is hydrated at render time, so a withdrawn
 * actor degrades to a fallback label instead of freezing stale text into the row.
 */
class NotificationFeedSerializer
{
    /**
     * @param  LengthAwarePaginator<int, DatabaseNotification>  $rows
     * @return array{data: list<array{id: string, kind: string, reason: ?string, createdAt: string, read: bool, actor: ?array{id: int, name: string, imageUrl: ?string}}>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $rows): array
    {
        $actors = self::actors(collect($rows->items()));

        return [
            'data' => array_map(fn (DatabaseNotification $row): array => self::row($row, $actors), $rows->items()),
            'meta' => [
                'currentPage' => $rows->currentPage(),
                'lastPage' => $rows->lastPage(),
                'perPage' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ];
    }

    /** The member the row is "about" (its avatar/name), or null for unknown kinds. */
    public static function actorId(DatabaseNotification $row): ?int
    {
        $data = $row->data;

        return match ($data['kind'] ?? null) {
            'friend_requested' => $data['requester_id'] ?? null,
            'friend_request_accepted' => $data['accepter_id'] ?? null,
            'message_received' => $data['sender_id'] ?? null,
            'diary_commented', 'community_topic_commented', 'community_event_commented' => $data['commenter_id'] ?? null,
            'community_joined' => $data['new_member_id'] ?? null,
            default => null,
        };
    }

    /**
     * Where opening the row lands, or null when there is nowhere sensible to go (unknown kind, or
     * a target that no longer exists) — the controller then returns to the feed.
     */
    public static function targetUrl(DatabaseNotification $row): ?string
    {
        $data = $row->data;

        return match ($data['kind'] ?? null) {
            'friend_requested' => '/m/friend/manage',
            'friend_request_accepted' => self::profileUrl($data['accepter_id'] ?? null),
            'message_received' => self::messageUrl($row, $data['message_id'] ?? null),
            'diary_commented' => self::diaryUrl($row, $data['diary_id'] ?? null),
            'community_topic_commented' => self::topicUrl($row, $data['topic_id'] ?? null),
            'community_event_commented' => self::eventUrl($row, $data['event_id'] ?? null),
            'community_joined' => self::communityUrl($data['community_id'] ?? null),
            default => null,
        };
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $rows
     * @return Collection<int, Member>
     */
    private static function actors(Collection $rows): Collection
    {
        $ids = $rows->map(fn (DatabaseNotification $row): ?int => self::actorId($row))->filter()->unique();

        return Member::with('avatar.file')->findMany($ids)->keyBy(fn (Member $member): int => $member->getKey());
    }

    /**
     * @param  Collection<int, Member>  $actors
     * @return array{id: string, kind: string, reason: ?string, createdAt: string, read: bool, actor: ?array{id: int, name: string, imageUrl: ?string}}
     */
    private static function row(DatabaseNotification $row, Collection $actors): array
    {
        $actor = $actors->get(self::actorId($row));

        return [
            'id' => $row->getKey(),
            'kind' => $row->data['kind'] ?? 'unknown',
            // Sub-discriminator for kinds that label by cause (a comment's reply/related).
            'reason' => $row->data['reason'] ?? null,
            'createdAt' => $row->created_at?->toISOString() ?? '',
            'read' => $row->read_at !== null,
            'actor' => $actor === null ? null : [
                'id' => $actor->getKey(),
                'name' => $actor->name,
                'imageUrl' => $actor->avatar?->file?->thumbnailUrl(76, 76, square: true),
            ],
        ];
    }

    private static function profileUrl(?int $memberId): ?string
    {
        if ($memberId === null || ! Member::whereKey($memberId)->exists()) {
            return null;
        }

        return '/m/member/'.$memberId;
    }

    /** A dissolved community counts as gone; the recipient is an admin, so no extra view gate. */
    private static function communityUrl(?int $communityId): ?string
    {
        if ($communityId === null || ! Community::whereKey($communityId)->exists()) {
            return null;
        }

        return '/m/community/'.$communityId;
    }

    /** A deleted diary — or one the recipient can no longer view — counts as gone. */
    private static function diaryUrl(DatabaseNotification $row, ?int $diaryId): ?string
    {
        $diary = $diaryId === null ? null : Diary::find($diaryId);
        if ($diary === null) {
            return null;
        }

        $viewer = Member::find($row->notifiable_id);

        return $viewer !== null && DiaryAccess::canView($viewer, $diary)
            ? '/m/diary/'.$diary->getKey()
            : null;
    }

    /** A deleted topic — or one whose board the recipient can no longer read — counts as gone. */
    private static function topicUrl(DatabaseNotification $row, ?int $topicId): ?string
    {
        $topic = $topicId === null ? null : CommunityTopic::find($topicId);
        if ($topic === null) {
            return null;
        }

        $viewer = Member::find($row->notifiable_id);

        return $viewer !== null && CommunityTopicAccess::canViewTopic($topic, $viewer)
            ? '/m/community/topic/'.$topic->getKey()
            : null;
    }

    /** The event twin of topicUrl(). */
    private static function eventUrl(DatabaseNotification $row, ?int $eventId): ?string
    {
        $event = $eventId === null ? null : CommunityEvent::find($eventId);
        if ($event === null) {
            return null;
        }

        $viewer = Member::find($row->notifiable_id);

        return $viewer !== null && CommunityEventAccess::canViewEvent($event, $viewer)
            ? '/m/community/event/'.$event->getKey()
            : null;
    }

    /**
     * The read page 404s unless the viewer still holds a live inbox receipt (ShowMessage's
     * Receive-box predicate), so a trashed/purged message counts as gone.
     */
    private static function messageUrl(DatabaseNotification $row, ?int $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $stillInInbox = MessageRecipient::query()->ofDelivered()->recipientLive()
            ->where('recipient_id', $row->notifiable_id)
            ->where('message_id', $messageId)
            ->exists();

        return $stillInInbox ? '/m/message/read/'.$messageId : null;
    }
}
