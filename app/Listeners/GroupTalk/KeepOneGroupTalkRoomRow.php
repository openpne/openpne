<?php

declare(strict_types=1);

namespace App\Listeners\GroupTalk;

use App\Features\GroupTalk\GroupTalkRoomNotificationRows;
use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMessagePostedNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * On `NotificationSent` rather than before the send, so the deletion can never outrun the insert.
 * Everything past the filters is wrapped in a `try`/`report()`: an escaping exception would skip
 * whichever listeners run after it, possibly the push, and fail the delivery after its row is written
 * (on `sync`, with the broadcast's remaining recipients).
 */
final class KeepOneGroupTalkRoomRow
{
    public function __construct(private readonly GroupTalkRoomNotificationRows $rows) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database'
            || ! $event->notification instanceof GroupTalkMessagePostedNotification
            || ! $event->notifiable instanceof Member) {
            return;
        }

        try {
            $this->rows->keepNewest(
                (int) $event->notifiable->getKey(),
                (int) $event->notification->message->group_id,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
