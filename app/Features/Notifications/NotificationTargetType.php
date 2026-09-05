<?php

declare(strict_types=1);

namespace App\Features\Notifications;

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
