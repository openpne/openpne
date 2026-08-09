<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Features\Notifications\Serializers\NotificationFeedSerializer;
use App\Models\Member;
use App\Notifications\Push\WebPushConfig;
use App\Notifications\Push\WebPushNudge;
use App\Support\PushDelivery;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * Nudges a member's subscribed devices whenever a feed row is written for them.
 *
 * Hooking the `database` send rather than each notification class is what makes push follow the
 * catalog for free: every opt-in, fan-out and feature gate has already decided by the time this
 * event fires, and a notification added later is covered without being told about push. The nudge
 * itself sends on WebPushChannel, so it cannot re-enter this filter.
 *
 * Everything past the two pure filters is wrapped: this runs inside the queued job that wrote the
 * feed row, so an exception escaping here retries that job and duplicates the row. A failed push is
 * reported and dropped instead.
 *
 * Guard order is deliberate — the config read is free, and the subscription probe is what makes a
 * member-wide fan-out one query per recipient instead of two, since most members have no device.
 */
final class SendWebPushNudge
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof Member) {
            return;
        }

        $member = $event->notifiable;

        try {
            if (! WebPushConfig::configured() || ! $member->pushSubscriptions()->exists()) {
                return;
            }

            if ($member->pushDelivery() !== PushDelivery::Enabled) {
                return;
            }

            $row = $event->response;
            assert($row instanceof DatabaseNotification);

            $member->notify(
                (new WebPushNudge(
                    $row->data['kind'] ?? null,
                    $row->data['reason'] ?? null,
                    NotificationFeedSerializer::actorId($row),
                ))->locale($member->locale ?? app()->getLocale()),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
