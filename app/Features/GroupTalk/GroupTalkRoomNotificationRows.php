<?php

declare(strict_types=1);

namespace App\Features\GroupTalk;

use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMessagePostedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * `data` is a TEXT column and no index covers `type`, so the SQL narrows by the notifiable index and
 * the group is matched in PHP. `markRead()` runs in a request, and inside the posting transaction,
 * so it reads only unread rows; `deleteOthers()` needs the read ones too and runs in the queued job.
 */
class GroupTalkRoomNotificationRows
{
    /** @return Collection<int, DatabaseNotification> the member's rows for this room */
    public function rowsFor(int $memberId, int $groupId, bool $unreadOnly = false): Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', (new Member)->getMorphClass())
            ->where('notifiable_id', $memberId)
            ->when($unreadOnly, static fn ($query) => $query->whereNull('read_at'))
            ->where('type', GroupTalkMessagePostedNotification::class)
            ->get()
            ->filter(static fn (DatabaseNotification $row): bool => (int) ($row->data['group_id'] ?? 0) === $groupId)
            ->values();
    }

    /**
     * Run after the new row is written, so a lost listener or a vetoed send can never leave the
     * member with nothing.
     */
    public function deleteOthers(int $memberId, int $groupId, string $keepId): void
    {
        $ids = $this->rowsFor($memberId, $groupId)
            ->reject(static fn (DatabaseNotification $row): bool => $row->getKey() === $keepId)
            ->map(static fn (DatabaseNotification $row): string => $row->getKey())
            ->all();

        if ($ids !== []) {
            DatabaseNotification::query()->whereKey($ids)->delete();
        }
    }

    public function markRead(int $memberId, int $groupId): void
    {
        $ids = $this->rowsFor($memberId, $groupId, unreadOnly: true)
            ->map(static fn (DatabaseNotification $row): string => $row->getKey())
            ->all();

        if ($ids !== []) {
            DatabaseNotification::query()->whereKey($ids)->update(['read_at' => now()]);
        }
    }
}
