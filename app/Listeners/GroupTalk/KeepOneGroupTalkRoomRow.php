<?php

declare(strict_types=1);

namespace App\Listeners\GroupTalk;

use App\Features\GroupTalk\GroupTalkRoomNotificationRows;
use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMessagePostedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * On `NotificationSent` rather than before the send, so the deletion can never outrun the insert; two
 * messages landing at once can briefly leave two rows, which the next one settles.
 * Everything past the filters is wrapped in a `try`/`report()`: this runs inside the queued job that
 * wrote the row, and an escaping exception would retry it and duplicate the row.
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
            $row = $event->response;
            assert($row instanceof DatabaseNotification);

            $this->rows->deleteOthers(
                (int) $event->notifiable->getKey(),
                (int) $event->notification->message->group_id,
                (string) $row->getKey(),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
