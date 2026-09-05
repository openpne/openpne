<?php

declare(strict_types=1);

namespace App\Notifications;

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

/**
 * A switched-off unit's rows are filtered by `notifications.type`, never by the payload's `kind`: that is a
 * nullable JSON path, and a row without the key would drop out of both sides of a comparison and disappear
 * silently. The list is the only thing here that can fall behind — which unit a class belongs to is read
 * off the class.
 */
final class FeatureNotificationMap
{
    /** @var list<class-string<FeatureNotification>> */
    public const CLASSES = [
        AdminTransferRequestedNotification::class,
        GroupJoinedNotification::class,
        SubAdminAppointedNotification::class,
        EventCommentBroadcastNotification::class,
        EventCommentedNotification::class,
        EventPostedNotification::class,
        GroupTalkMentionedNotification::class,
        GroupTalkMessagePostedNotification::class,
        TopicCommentBroadcastNotification::class,
        TopicCommentedNotification::class,
        TopicPostedNotification::class,
        DiaryCommentedNotification::class,
        DiaryPostedNotification::class,
        FriendRequestAcceptedNotification::class,
        FriendRequestedNotification::class,
        DirectMessageReceivedNotification::class,
        TimelineMentionedNotification::class,
        TimelinePostedNotification::class,
        TimelineRepliedNotification::class,
    ];

    /** @return list<class-string<FeatureNotification>> */
    public static function disabledTypes(): array
    {
        return array_values(array_filter(
            self::CLASSES,
            static fn (string $class): bool => ! $class::feature()->enabled(),
        ));
    }
}
