<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Why a comment notification reaches a recipient: on their own content (Reply), on content they also
 * commented on (Related), or as a member of the content's community (Community — the broadcast). Each
 * maps to its own catalog kind, so a member can keep replies while muting the co-commenter or the
 * community-wide stream. One notification per recipient, precedence Reply > Related > Community: the
 * broadcast audience excludes the author and co-commenters, who already get Reply / Related.
 */
enum CommentReason: string
{
    case Reply = 'reply';
    case Related = 'related';
    case Community = 'community';
}
