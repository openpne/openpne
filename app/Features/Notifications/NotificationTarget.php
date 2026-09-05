<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Notifications\Diary\DiaryCommentedNotification;
use App\Notifications\Diary\DiaryPostedNotification;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use App\Notifications\Friend\FriendRequestAcceptedNotification;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Group\AdminTransferRequestedNotification;
use App\Notifications\Group\GroupJoinedNotification;
use App\Notifications\Group\SubAdminAppointedNotification;
use App\Notifications\GroupEvent\EventCommentBroadcastNotification;
use App\Notifications\GroupEvent\EventCommentedNotification;
use App\Notifications\GroupEvent\EventPostedNotification;
use App\Notifications\GroupTalk\GroupTalkMentionedNotification;
use App\Notifications\GroupTalk\GroupTalkMessagePostedNotification;
use App\Notifications\GroupTopic\TopicCommentBroadcastNotification;
use App\Notifications\GroupTopic\TopicCommentedNotification;
use App\Notifications\GroupTopic\TopicPostedNotification;
use App\Notifications\Timeline\TimelineMentionedNotification;
use App\Notifications\Timeline\TimelinePostedNotification;
use App\Notifications\Timeline\TimelineRepliedNotification;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The single table saying which `data` ids each kind points at, read both by the URL a row opens and by
 * the rule that reading that target marks the row read, so a row can never open one place and be consumed
 * by another.
 */
final class NotificationTarget
{
    private function __construct(
        public readonly NotificationTargetType $type,
        /** The entity the target names; 0 where the target is a page rather than an entity. */
        public readonly int $id,
    ) {}

    /** The target a row points at, or null for an unknown kind or a row missing its id. */
    public static function of(DatabaseNotification $row): ?self
    {
        $data = $row->data;

        return match ($data['kind'] ?? null) {
            'friend_requested' => self::friendRequests(),
            'friend_request_accepted' => self::at(NotificationTargetType::Member, $data['accepter_id'] ?? null),
            'direct_message_received' => self::at(NotificationTargetType::DirectMessage, $data['direct_message_id'] ?? null),
            'diary_commented', 'diary_posted' => self::at(NotificationTargetType::Diary, $data['diary_id'] ?? null),
            'group_topic_commented', 'group_topic_posted' => self::at(NotificationTargetType::Topic, $data['topic_id'] ?? null),
            'group_event_commented', 'group_event_posted' => self::at(NotificationTargetType::Event, $data['event_id'] ?? null),
            'group_joined', 'group_admin_transfer_requested', 'group_sub_admin_appointed' => self::at(NotificationTargetType::Group, $data['group_id'] ?? null),
            'group_talk_mention' => self::at(NotificationTargetType::TalkMessage, $data['message_id'] ?? null),
            'group_talk_new_message' => self::at(NotificationTargetType::TalkRoom, $data['group_id'] ?? null),
            'timeline_mentioned', 'timeline_posted', 'timeline_replied' => self::at(NotificationTargetType::TimelinePost, $data['post_id'] ?? null),
            default => null,
        };
    }

    public static function friendRequests(): self
    {
        return new self(NotificationTargetType::FriendRequests, 0);
    }

    public static function member(int $id): self
    {
        return new self(NotificationTargetType::Member, $id);
    }

    public static function diary(int $id): self
    {
        return new self(NotificationTargetType::Diary, $id);
    }

    public static function topic(int $id): self
    {
        return new self(NotificationTargetType::Topic, $id);
    }

    public static function event(int $id): self
    {
        return new self(NotificationTargetType::Event, $id);
    }

    public static function group(int $id): self
    {
        return new self(NotificationTargetType::Group, $id);
    }

    public static function timelinePost(int $id): self
    {
        return new self(NotificationTargetType::TimelinePost, $id);
    }

    /** Identity for equality matching — the pair is the whole value. */
    public function key(): string
    {
        return $this->type->name.':'.$this->id;
    }

    /**
     * `notifications.type` is what a row is narrowed by everywhere (`data` is a TEXT column with no path
     * index), so this is what keeps a community page from sweeping the room's talk row beside it.
     *
     * @return list<class-string>
     */
    public function classes(): array
    {
        return match ($this->type) {
            NotificationTargetType::FriendRequests => [FriendRequestedNotification::class],
            NotificationTargetType::Member => [FriendRequestAcceptedNotification::class],
            NotificationTargetType::DirectMessage => [DirectMessageReceivedNotification::class],
            NotificationTargetType::Diary => [DiaryCommentedNotification::class, DiaryPostedNotification::class],
            NotificationTargetType::Topic => [TopicCommentedNotification::class, TopicCommentBroadcastNotification::class, TopicPostedNotification::class],
            NotificationTargetType::Event => [EventCommentedNotification::class, EventCommentBroadcastNotification::class, EventPostedNotification::class],
            NotificationTargetType::Group => [GroupJoinedNotification::class, AdminTransferRequestedNotification::class, SubAdminAppointedNotification::class],
            NotificationTargetType::TalkMessage => [GroupTalkMentionedNotification::class],
            NotificationTargetType::TalkRoom => [GroupTalkMessagePostedNotification::class],
            NotificationTargetType::TimelinePost => [TimelineMentionedNotification::class, TimelinePostedNotification::class, TimelineRepliedNotification::class],
        };
    }

    private static function at(NotificationTargetType $type, mixed $id): ?self
    {
        return $id === null ? null : new self($type, (int) $id);
    }
}
