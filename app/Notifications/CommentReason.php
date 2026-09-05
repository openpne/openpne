<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Why a comment notification reaches a recipient: their own content (Reply), content they also
 * commented on (Related), or the content's group (Group — the broadcast). Precedence is Reply >
 * Related > Group (docs/internals/notifications.md, "Broadcast fan-out").
 */
enum CommentReason: string
{
    case Reply = 'reply';
    case Related = 'related';
    case Group = 'group';
}
