<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Why a comment notification reaches a recipient: on their own content (Reply) or on content
 * they also commented on (Related). Each maps to its own catalog kind, so a member can keep
 * replies while muting the co-commenter stream. One notification per recipient — Reply wins
 * when both apply.
 */
enum CommentReason: string
{
    case Reply = 'reply';
    case Related = 'related';
}
