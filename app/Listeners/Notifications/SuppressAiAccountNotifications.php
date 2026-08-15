<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Models\Member;
use Illuminate\Notifications\Events\NotificationSending;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Keeps the outbound channels shut for an AI account: no mail, no web push.
 *
 * An AI account has nobody to interrupt. Its address column is null, so mail would already fail —
 * this says the same thing once, deliberately, at the point every notification passes, rather than
 * leaving it as a side effect of a missing column that a later feature could fill in. Push is the
 * case with no such accident behind it: a subscription is per browser, and nothing stops one from
 * being registered against any member.
 *
 * The `database` channel is deliberately let through. The feed row is the account's own record of
 * what happened to it — what an MCP client reads to find out it was mentioned — so suppressing the
 * delivery must not suppress the fact.
 *
 * Hooked at NotificationSending (which halts the send when a listener returns false) rather than in
 * each notification's via(), so a notification added later is covered without being told about this.
 * Null, not true, for everything else: the event is dispatched with halt, so any non-null answer
 * ends the chain and would silence a second opinion this listener has no view on.
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
