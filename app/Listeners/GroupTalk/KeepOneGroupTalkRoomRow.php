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
 * Leaves the member one feed row per talk room: once the new row is written, the room's earlier ones
 * go.
 *
 * On `NotificationSent` rather than before the send, so the deletion can never outrun the insert —
 * a vetoed send or a listener that never ran would otherwise take the member's only row with it.
 * Two messages landing at once can briefly leave two rows; the next one settles it.
 *
 * Everything past the pure filters is wrapped in a `try`/`report()` for the reason
 * App\Listeners\Notifications\SendWebPushNudge is: this runs inside the queued job that wrote the
 * row, so an escaping exception would retry that job and duplicate the row it is here to deduplicate.
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
