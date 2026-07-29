<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Models\Member;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Everything the notification center knows about: the member's most recent events, capped where
 * OpenPNE 3 capped them.
 *
 * The cap is the center's whole world, not a page of it. OpenPNE 3 sliced the stored array to
 * `op_notification_limit` as it wrote, so its badge counts and its panel read the same 20 items by
 * construction. Counting outside the window would put a number on the sprite that the panel cannot
 * account for — a badge over rows that are not there.
 *
 * Bound request-scoped: within a request the window is read once, however many surfaces ask (the
 * shell's badges and the panel arrive on different requests, so each runs its own query).
 */
class NotificationCenterWindow
{
    public const LIMIT = 20;

    /** @var array<int, Collection<int, DatabaseNotification>> */
    private array $cache = [];

    /** @return Collection<int, DatabaseNotification> */
    public function for(Member $viewer): Collection
    {
        // created_at is second-granular and the key is a random UUID, so a second that produced
        // more rows than the cap needs a tiebreak or "the newest 20" is not a defined set — the
        // badge request and the panel request could window differently. The UUID is the contract:
        // arbitrary, but the same arbitrary for every reader. (reorder: the relation itself adds
        // a latest() this would otherwise stack under.)
        return $this->cache[$viewer->getKey()] ??= $viewer->notifications()
            ->reorder('created_at', 'desc')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }
}
