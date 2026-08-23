<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Models\Member;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The feed is an inbox, so a row is spent once the member has read what it announces — not only when
 * the row itself is opened (docs/internals/notifications.md).
 *
 * Rows are the starting point rather than the domain: `data` is a TEXT column with no path index, so
 * the SQL narrows by what `notifications_notifiable_read_at_index` covers plus `type`, and the entity
 * ids are matched in PHP over the member's own unread rows, which are few.
 *
 * The room's talk row is not consumed here — it is one row per (member, group) with a delete rule of
 * its own (GroupTalkRoomNotificationRows).
 */
class ConsumeNotificationRows
{
    /** Mark the member's unread rows pointing at any of these targets read. */
    public function markTargetsRead(int $memberId, NotificationTarget ...$targets): void
    {
        if ($targets === []) {
            return;
        }

        $wanted = [];
        $types = [];
        foreach ($targets as $target) {
            $wanted[$target->key()] = true;
            foreach ($target->classes() as $class) {
                $types[$class] = true;
            }
        }

        $ids = $this->unreadRows($memberId, ...array_keys($types))
            ->filter(static function (DatabaseNotification $row) use ($wanted): bool {
                $target = NotificationTarget::of($row);

                return $target !== null && isset($wanted[$target->key()]);
            })
            ->map(static fn (DatabaseNotification $row): string => $row->getKey())
            ->all();

        $this->markRead(...$ids);
    }

    /**
     * The member's unread rows of these types, for a caller matching them against a read cursor of
     * its own. Naming no type asks for nothing.
     *
     * @return Collection<int, DatabaseNotification>
     */
    public function unreadRows(int $memberId, string ...$types): Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', (new Member)->getMorphClass())
            ->where('notifiable_id', $memberId)
            ->whereNull('read_at')
            ->whereIn('type', $types)
            ->get();
    }

    public function markRead(string ...$ids): void
    {
        if ($ids === []) {
            return;
        }

        DatabaseNotification::query()->whereKey($ids)->update(['read_at' => now()]);
    }
}
