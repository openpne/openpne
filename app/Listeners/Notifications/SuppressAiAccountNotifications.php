<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Models\Member;
use Illuminate\Notifications\Events\NotificationSending;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Drops mail and web push for an AI account at NotificationSending, letting the `database` row
 * through (docs/internals/notifications.md, "Gating flow"). Null, not true, for everything else:
 * the event is dispatched with halt, so any non-null answer ends the chain and would silence a
 * second opinion.
 */
final class SuppressAiAccountNotifications
{
    public function handle(NotificationSending $event): ?bool
    {
        if (! $event->notifiable instanceof Member || ! $event->notifiable->isAiAccount()) {
            return null;
        }

        return in_array($event->channel, ['mail', WebPushChannel::class], true) ? false : null;
    }
}
