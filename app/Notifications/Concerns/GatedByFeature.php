<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

/**
 * Drops a feature-owned notification while its unit is switched off.
 *
 * shouldSend() is the only choke that holds: NotificationSender consults it immediately before each
 * channel send, queued or not. via() cannot do this — it runs when the notification is *queued*, and
 * SendQueuedNotifications replays the channels decided back then, so a unit switched off in between
 * would never be re-read there. For the same reason nothing here belongs in templateChannels*().
 */
trait GatedByFeature
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return static::feature()->enabled();
    }
}
