<?php

declare(strict_types=1);

namespace App\Notifications\Push;

/**
 * The site-level switch for web push: a VAPID keypair is what lets this site sign a push at all, so
 * an install that has not set one has the feature off entirely — no shared prop, no subscribe
 * endpoint, nothing sent. One predicate so those three cannot disagree; there is deliberately no
 * administrator setting beside it (the keys are the switch).
 */
final class WebPushConfig
{
    public static function configured(): bool
    {
        return (string) config('webpush.vapid.public_key') !== ''
            && (string) config('webpush.vapid.private_key') !== '';
    }
}
