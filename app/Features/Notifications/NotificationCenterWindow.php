<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Models\Member;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Everything the notification centre knows about: the member's most recent events, capped where
 * OpenPNE 3 capped them.
 *
 * The cap is the centre's whole world, not a page of it. OpenPNE 3 sliced the stored array to
 * `op_notification_limit` as it wrote, so its badge counts and its panel read the same 20 items by
 * construction. Counting outside the window would put a number on the sprite that the panel cannot
 * account for — a badge over rows that are not there.
 *
 * Bound request-scoped: the shell asks on every page, and the panel asks again for the same window.
 */
class NotificationCenterWindow
{
    public const LIMIT = 20;

    /** @var array<int, Collection<int, DatabaseNotification>> */
    private array $cache = [];

    /** @return Collection<int, DatabaseNotification> */
    public function for(Member $viewer): Collection
    {
        return $this->cache[$viewer->getKey()] ??= $viewer->notifications()
            ->latest()
            ->limit(self::LIMIT)
            ->get();
    }
}
