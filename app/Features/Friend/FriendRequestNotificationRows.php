<?php

declare(strict_types=1);

namespace App\Features\Friend;

use App\Features\Notifications\ConsumeNotificationRows;
use App\Notifications\Friend\FriendRequestedNotification;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Answering one requester spends that requester's rows only; the answerer's other pending requests
 * are still waiting.
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
