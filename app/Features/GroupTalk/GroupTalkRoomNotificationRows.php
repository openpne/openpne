<?php

declare(strict_types=1);

namespace App\Features\GroupTalk;

use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMessagePostedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The room's feed row: at most one `notifications` row per (member, group) for the talk broadcast,
 * read or unread. A chat that left a row per message would bury every other notification, so the new
 * row replaces the room's previous ones and reading the room marks the survivor read.
 *
 * `data` is a TEXT column, so the group is matched in PHP rather than in SQL. No index covers `type`,
 * so the SQL narrows by what the notifiable indexes do cover: markRead() runs in the request (and
 * inside the posting transaction), so it reads only the member's unread rows; deleteOthers() needs
 * the read ones too and runs in the queued job, where reading the member's whole feed is affordable.
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
     * Drop the room's other rows, keeping $keepId — run AFTER the new row is written, so a lost
     * listener or a vetoed send can never leave the member with nothing. Read rows go too: the row is
     * the room's, not the message's.
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

    /**
     * Mark the room's row read. Called unconditionally on every read of the room, cursor moved or
     * not: another tab may have moved it already, and an update over an already-read row costs
     * nothing.
     */
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
