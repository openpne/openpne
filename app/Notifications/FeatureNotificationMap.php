<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Community\AdminTransferRequestedNotification;
use App\Notifications\Community\CommunityJoinedNotification;
use App\Notifications\Community\SubAdminAppointedNotification;
use App\Notifications\CommunityEvent\EventCommentBroadcastNotification;
use App\Notifications\CommunityEvent\EventCommentedNotification;
use App\Notifications\CommunityEvent\EventPostedNotification;
use App\Notifications\CommunityTopic\TopicCommentBroadcastNotification;
use App\Notifications\CommunityTopic\TopicCommentedNotification;
use App\Notifications\CommunityTopic\TopicPostedNotification;
use App\Notifications\Diary\DiaryCommentedNotification;
use App\Notifications\Diary\DiaryPostedNotification;
use App\Notifications\Friend\FriendRequestAcceptedNotification;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Message\MessageReceivedNotification;

/**
 * Every feature-owned notification class, for the display side: `notifications.type` holds exactly
 * these strings, which is why a switched-off unit's rows are filtered by type and never by the
 * payload's `kind` — that is a nullable JSON path, where a row without the key would drop out of
 * both sides of a comparison and disappear silently.
 *
 * The list is the only thing here that can fall behind; which unit a class belongs to is read off
 * the class. Tests\Feature\Architecture\FeatureNotificationCoverageTest walks the feature
 * notification namespaces and fails on a class that is missing here or does not declare its unit.
 */
final class FeatureNotificationMap
{
    /** @var list<class-string<FeatureNotification>> */
    public const CLASSES = [
        AdminTransferRequestedNotification::class,
        CommunityJoinedNotification::class,
        SubAdminAppointedNotification::class,
        EventCommentBroadcastNotification::class,
        EventCommentedNotification::class,
        EventPostedNotification::class,
        TopicCommentBroadcastNotification::class,
        TopicCommentedNotification::class,
        TopicPostedNotification::class,
        DiaryCommentedNotification::class,
        DiaryPostedNotification::class,
        FriendRequestAcceptedNotification::class,
        FriendRequestedNotification::class,
        MessageReceivedNotification::class,
    ];

    /** @return list<class-string<FeatureNotification>> the classes whose unit is switched off */
    public static function disabledTypes(): array
    {
        return array_values(array_filter(
            self::CLASSES,
            static fn (string $class): bool => ! $class::feature()->enabled(),
        ));
    }
}
