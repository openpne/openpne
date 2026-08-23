<?php

declare(strict_types=1);

namespace App\Features\Friend;

use App\Features\Notifications\ConsumeNotificationRows;
use App\Notifications\Friend\FriendRequestedNotification;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The feed row for a %friend% request, spent by answering it — one requester's row, not the
 * requests page's worth, since the other requests are still waiting. The Classic notification
 * center answers inline and retires its own row; this gives the `/friend/*` forms the same meaning.
 */
class FriendRequestNotificationRows
{
    public function __construct(private readonly ConsumeNotificationRows $rows) {}

    public function markAnswered(int $answererId, int $requesterId): void
    {
        $ids = $this->rows->unreadRows($answererId, FriendRequestedNotification::class)
            ->filter(static fn (DatabaseNotification $row): bool => (int) ($row->data['requester_id'] ?? 0) === $requesterId)
            ->map(static fn (DatabaseNotification $row): string => $row->getKey())
            ->all();

        $this->rows->markRead(...$ids);
    }
}
