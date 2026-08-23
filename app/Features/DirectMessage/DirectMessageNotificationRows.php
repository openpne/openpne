<?php

declare(strict_types=1);

namespace App\Features\DirectMessage;

use App\Features\Notifications\ConsumeNotificationRows;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The feed rows for received messages, spent by the receipts they point at.
 *
 * The receipt is what both read paths agree on — the mailbox opens one message, the conversation
 * marks everything up to a position — so the rows are matched against `read_at` after the fact
 * rather than against whichever ids the caller believes it just covered.
 */
class DirectMessageNotificationRows
{
    public function __construct(private readonly ConsumeNotificationRows $rows) {}

    public function markReadFor(Member $viewer): void
    {
        $unread = $this->rows->unreadRows((int) $viewer->getKey(), DirectMessageReceivedNotification::class);
        if ($unread->isEmpty()) {
            return;
        }

        $read = DirectMessageRecipient::query()
            ->where('recipient_id', $viewer->getKey())
            ->whereNotNull('read_at')
            ->whereIn('direct_message_id', $unread->map($this->messageId(...))->all())
            ->pluck('direct_message_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();

        $ids = $unread
            ->filter(fn (DatabaseNotification $row): bool => $read->has($this->messageId($row)))
            ->map(static fn (DatabaseNotification $row): string => $row->getKey())
            ->all();

        $this->rows->markRead(...$ids);
    }

    private function messageId(DatabaseNotification $row): int
    {
        return (int) ($row->data['direct_message_id'] ?? 0);
    }
}
