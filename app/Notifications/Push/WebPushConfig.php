<?php

declare(strict_types=1);

namespace App\Notifications\Push;

/**
 * The single predicate the shared prop, the subscribe endpoints and the sender all read, so the three
 * cannot disagree (docs/internals/notifications.md, "Web push").
 */
final class WebPushConfig
{
    public static function configured(): bool
    {
        return (string) config('webpush.vapid.public_key') !== ''
            && (string) config('webpush.vapid.private_key') !== '';
    }
}
