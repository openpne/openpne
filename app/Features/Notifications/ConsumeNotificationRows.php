<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Models\Member;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The feed is an inbox, so a row is spent once the member has read what it announces
 * (docs/internals/notifications.md, "The three layers"). `data` is a TEXT column with no path
 * index, so the SQL narrows by `notifications_notifiable_read_at_index` plus `type` and the ids are
 * matched in PHP over the member's few unread rows.
 */
class ConsumeNotificationRows
{
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
     * Naming no type asks for nothing.
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
