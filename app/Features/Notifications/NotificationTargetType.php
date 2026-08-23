<?php

declare(strict_types=1);

namespace App\Features\Notifications;

/** What a feed row lands on. The cases a NotificationTarget can carry — see NotificationTarget. */
enum NotificationTargetType
{
    case FriendRequests;
    case Member;
    case DirectMessage;
    case Diary;
    case Topic;
    case Event;
    case Group;
    case TalkMessage;
    case TalkRoom;
    case TimelinePost;
}
