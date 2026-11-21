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
 * Everything past the two pure filters is wrapped: an escaping exception would skip the event's later
 * listeners and fail the delivery after its row is written, on `sync` along with whatever sent it
 * (docs/internals/notifications.md, "Web push").
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
                (new WebPushNudge($row->data, NotificationFeedSerializer::actorId($row)))->locale($member->locale ?? app()->getLocale()),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
