<?php

namespace App\Features\Notifications\Serializers;

use App\Features\Diary\DiaryAccess;
use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Features\Member\MemberDisplayName;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Notifications\NotificationCenterCategory;
use App\Features\Notifications\NotificationCenterRow;
use App\Features\Notifications\NotificationFeedRow;
use App\Features\Notifications\NotificationKindLabel;
use App\Features\Notifications\NotificationTarget;
use App\Features\Notifications\NotificationTargetType;
use App\Features\Notifications\Queries\ListNotificationCenterRows;
use App\Features\Timeline\TimelineAccess;
use App\Models\Diary;
use App\Models\DirectMessageRecipient;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Feed shapes for the per-event notification rows (layer 3). Rows store only the kind
 * discriminator and entity ids; everything displayed is hydrated at render time, so a withdrawn
 * actor degrades to a fallback label instead of freezing stale text into the row.
 */
class NotificationFeedSerializer
{
    /**
     * @param  LengthAwarePaginator<int, DatabaseNotification>  $rows
     * @return array{data: list<array{id: string, kind: string, reason: ?string, label: string, createdAt: string, read: bool, actor: ?array{id: int, name: string, imageUrl: ?string, avatarColor: ?string, isAi: bool}}>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
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

    /**
     * The same rows for the Classic list, which pages with <x-classic.pager> and so needs the
     * paginator itself rather than a meta array.
     *
     * @param  LengthAwarePaginator<int, DatabaseNotification>  $rows
     * @return LengthAwarePaginator<int, NotificationFeedRow>
     */
    public static function classicRows(LengthAwarePaginator $rows): LengthAwarePaginator
    {
        $actors = self::actors(collect($rows->items()));

        return $rows->through(fn (DatabaseNotification $row): NotificationFeedRow => new NotificationFeedRow(
            id: $row->getKey(),
            label: self::label($row, $actors),
            createdAt: $row->created_at,
            read: $row->read_at !== null,
        ));
    }

    /**
     * Rows for the Classic notification center panel. Takes the requesters whose %friend% request
     * is still open so the panel can offer the decision inline; resolving that per row would be a
     * query per row.
     *
     * @param  Collection<int, DatabaseNotification>  $rows
     * @param  array<int, bool>  $awaitingByRequester  keyed by requester id
     * @return Collection<int, NotificationCenterRow>
     */
    public static function centerRows(Collection $rows, array $awaitingByRequester): Collection
    {
        $actors = self::actors($rows);

        return $rows->map(function (DatabaseNotification $row) use ($actors, $awaitingByRequester): NotificationCenterRow {
            $actor = $actors->get(self::actorId($row));
            $category = NotificationCenterCategory::for($row->data['kind'] ?? null);

            return new NotificationCenterRow(
                id: $row->getKey(),
                label: self::label($row, $actors),
                category: $category,
                read: $row->read_at !== null,
                actorId: $actor?->getKey(),
                actorName: MemberDisplayName::of($actor),
                actorAvatar: $actor?->avatar?->file,
                awaitingDecision: $category === NotificationCenterCategory::Friend
                    && isset($awaitingByRequester[ListNotificationCenterRows::requesterId($row) ?? 0]),
            );
        })->values();
    }

    /** The member the row is "about" (its avatar/name), or null for unknown kinds. */
    public static function actorId(DatabaseNotification $row): ?int
    {
        $data = $row->data;

        return match ($data['kind'] ?? null) {
            'friend_requested' => $data['requester_id'] ?? null,
            'friend_request_accepted' => $data['accepter_id'] ?? null,
            'direct_message_received' => $data['sender_id'] ?? null,
            'diary_commented', 'group_topic_commented', 'group_event_commented' => $data['commenter_id'] ?? null,
            'timeline_replied' => $data['replier_id'] ?? null,
            'group_joined' => $data['new_member_id'] ?? null,
            'group_admin_transfer_requested' => $data['requester_id'] ?? null,
            'group_sub_admin_appointed' => $data['appointer_id'] ?? null,
            'diary_posted', 'group_talk_mention', 'group_talk_new_message', 'group_topic_posted', 'group_event_posted', 'timeline_mentioned', 'timeline_posted' => $data['author_id'] ?? null,
            default => null,
        };
    }

    /**
     * Where opening the row lands, or null when there is nowhere sensible to go (unknown kind, or
     * a target that no longer exists) — the controller then returns to the feed.
     */
    public static function targetUrl(DatabaseNotification $row): ?string
    {
        $target = NotificationTarget::of($row);

        // Which entity the row names is NotificationTarget's table; whether it still exists and the
        // reader may still see it is asked here, at click time.
        return match ($target?->type) {
            NotificationTargetType::FriendRequests => '/friend/requests',
            NotificationTargetType::Member => self::profileUrl($target->id),
            NotificationTargetType::DirectMessage => self::directMessageUrl($row, $target->id),
            NotificationTargetType::Diary => self::diaryUrl($row, $target->id),
            NotificationTargetType::Topic => self::topicUrl($row, $target->id),
            NotificationTargetType::Event => self::eventUrl($row, $target->id),
            NotificationTargetType::Group => self::groupUrl($target->id),
            NotificationTargetType::TalkMessage => self::groupTalkUrl($row, $target->id),
            NotificationTargetType::TalkRoom => self::groupTalkRoomUrl($row, $target->id),
            NotificationTargetType::TimelinePost => self::timelineUrl($row, $target->id),
            null => null,
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
     * @return array{id: string, kind: string, reason: ?string, label: string, createdAt: string, read: bool, actor: ?array{id: int, name: string, imageUrl: ?string, avatarColor: ?string, isAi: bool}}
     */
    private static function row(DatabaseNotification $row, Collection $actors): array
    {
        $actor = $actors->get(self::actorId($row));

        return [
            'id' => $row->getKey(),
            'kind' => $row->data['kind'] ?? 'unknown',
            // Sub-discriminator for kinds that label by cause (a comment's reply/related).
            'reason' => $row->data['reason'] ?? null,
            'label' => self::label($row, $actors),
            'createdAt' => $row->created_at?->toISOString() ?? '',
            'read' => $row->read_at !== null,
            'actor' => $actor === null ? null : MemberRefSerializer::ref($actor),
        ];
    }

    /** @param  Collection<int, Member>  $actors */
    private static function label(DatabaseNotification $row, Collection $actors): string
    {
        return NotificationKindLabel::for(
            $row->data['kind'] ?? null,
            $row->data['reason'] ?? null,
            MemberDisplayName::of($actors->get(self::actorId($row))),
        );
    }

    private static function profileUrl(int $memberId): ?string
    {
        if (! Member::whereKey($memberId)->exists()) {
            return null;
        }

        return '/member/'.$memberId;
    }

    /** A dissolved community counts as gone; the recipient is an admin, so no extra view gate. */
    private static function groupUrl(int $groupId): ?string
    {
        if (! Group::whereKey($groupId)->exists()) {
            return null;
        }

        return '/groups/'.$groupId;
    }

    /** A deleted diary — or one the recipient can no longer view — counts as gone. */
    private static function diaryUrl(DatabaseNotification $row, int $diaryId): ?string
    {
        $diary = Diary::find($diaryId);
        if ($diary === null) {
            return null;
        }

        $viewer = Member::find($row->notifiable_id);

        return $viewer !== null && DiaryAccess::canView($viewer, $diary)
            ? '/diary/'.$diary->getKey()
            : null;
    }

    /** A deleted topic — or one whose board the recipient can no longer read — counts as gone. */
    private static function topicUrl(DatabaseNotification $row, int $topicId): ?string
    {
        $topic = GroupTopic::find($topicId);
        if ($topic === null) {
            return null;
        }

        $viewer = Member::find($row->notifiable_id);

        return $viewer !== null && GroupTopicAccess::canViewTopic($topic, $viewer)
            ? '/topics/'.$topic->getKey()
            : null;
    }

    /** The event twin of topicUrl(). */
    private static function eventUrl(DatabaseNotification $row, int $eventId): ?string
    {
        $event = GroupEvent::find($eventId);
        if ($event === null) {
            return null;
        }

        $viewer = Member::find($row->notifiable_id);

        return $viewer !== null && GroupEventAccess::canViewEvent($event, $viewer)
            ? '/events/'.$event->getKey()
            : null;
    }

    /**
     * The conversation the mentioning message sits in, opened on that message (`?m=`). Talk has no
     * screen of its own for one message, so the anchor is a place in the conversation rather than a
     * permalink — see group-talk.md.
     *
     * Re-checked at click time, not trusted from delivery: the message may have been deleted since,
     * and the reader may have left a members-only group or lost read access with it.
     */
    private static function groupTalkUrl(DatabaseNotification $row, int $messageId): ?string
    {
        $message = GroupMessage::find($messageId);
        if ($message === null) {
            return null;
        }

        $viewer = Member::find($row->notifiable_id);
        $group = $message->group;

        return $viewer !== null && $group !== null && GroupTalkAccess::canView($group, $viewer)
            ? '/groups/'.$group->getKey().'/talk?m='.$message->getKey()
            : null;
    }

    /**
     * The room itself, with no `?m=`: the row stands for everything said there since the member last
     * looked, and talk opens on the unread boundary, which is where they want to arrive.
     *
     * Re-checked at click time like every other target: the group may have dissolved, or the reader
     * may have left a members-only one since.
     */
    private static function groupTalkRoomUrl(DatabaseNotification $row, int $groupId): ?string
    {
        $group = Group::find($groupId);
        if ($group === null) {
            return null;
        }

        $viewer = Member::find($row->notifiable_id);

        return $viewer !== null && GroupTalkAccess::canView($group, $viewer)
            ? '/groups/'.$group->getKey().'/talk'
            : null;
    }

    /**
     * A deleted post — or a thread the recipient can no longer view — counts as gone. A thread has
     * one address, so a row about a reply opens the root; the whole thread is one audience, which
     * is also what the clearance is read against.
     */
    private static function timelineUrl(DatabaseNotification $row, int $postId): ?string
    {
        $post = TimelinePost::find($postId);
        $root = $post === null || $post->in_reply_to_id === null ? $post : $post->parent;
        if ($root === null) {
            return null;
        }

        $viewer = Member::find($row->notifiable_id);

        return $viewer !== null && TimelineAccess::canView($viewer, $root)
            ? '/timeline/'.$root->getKey()
            : null;
    }

    /**
     * The read page 404s unless the viewer still holds a live inbox receipt (ShowDirectMessage's
     * Receive-box predicate), so a trashed/purged message counts as gone.
     */
    private static function directMessageUrl(DatabaseNotification $row, int $messageId): ?string
    {
        $stillInInbox = DirectMessageRecipient::query()->ofDelivered()->recipientLive()
            ->where('recipient_id', $row->notifiable_id)
            ->where('direct_message_id', $messageId)
            ->exists();

        return $stillInInbox ? '/message/read/'.$messageId : null;
    }
}
