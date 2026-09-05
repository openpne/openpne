<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

/**
 * shouldSend() is the only choke that holds: NotificationSender consults it immediately before each channel
 * send, queued or not, while via() runs at enqueue time (docs/internals/notifications.md, Gating flow).
 */
trait GatedByFeature
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return static::feature()->enabled();
    }
}
