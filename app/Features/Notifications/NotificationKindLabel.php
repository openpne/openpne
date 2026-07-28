<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Notifications\CommentReason;

/**
 * The sentence a feed row reads as: the kind picks the wording, the comment kinds' reason picks
 * which of their three it is, and the actor's name fills it — the withdrawn-member fallback when
 * the row's actor is gone. Resolved server-side so both surfaces print one sentence from one
 * source; an unknown kind still gets a line rather than an empty row.
 */
final class NotificationKindLabel
{
    public static function for(?string $kind, ?string $reason, ?string $actorName): string
    {
        $name = $actorName ?? __('Withdrawn member');
        $cause = $reason === null ? null : CommentReason::tryFrom($reason);

        return match ($kind) {
            'friend_requested' => __(':name sent you a %friend% request.', ['name' => $name]),
            'friend_request_accepted' => __(':name accepted your %friend% request.', ['name' => $name]),
            'message_received' => __(':name sent you a message.', ['name' => $name]),
            'diary_commented' => $cause === CommentReason::Related
                ? __(':name commented on a %diary% you commented on.', ['name' => $name])
                : __(':name commented on your %diary%.', ['name' => $name]),
            'community_topic_commented' => match ($cause) {
                CommentReason::Related => __(':name commented on a %topic% you commented on.', ['name' => $name]),
                CommentReason::Community => __(':name commented on a %topic% in your %community%.', ['name' => $name]),
                default => __(':name commented on your %topic%.', ['name' => $name]),
            },
            'community_event_commented' => match ($cause) {
                CommentReason::Related => __(':name commented on an event you commented on.', ['name' => $name]),
                CommentReason::Community => __(':name commented on an event in your %community%.', ['name' => $name]),
                default => __(':name commented on your event.', ['name' => $name]),
            },
            'community_joined' => __(':name joined your %community%.', ['name' => $name]),
            'community_admin_transfer_requested' => __(':name asked you to take over a %community% administration.', ['name' => $name]),
            'community_sub_admin_appointed' => __(':name appointed you as a %community% sub-administrator.', ['name' => $name]),
            'diary_posted' => __(':name posted a new %diary%.', ['name' => $name]),
            'community_topic_posted' => __(':name posted a new %topic%.', ['name' => $name]),
            'community_event_posted' => __(':name posted a new event.', ['name' => $name]),
            default => __('New notification'),
        };
    }
}
