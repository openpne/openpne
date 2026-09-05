<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Features\Notifications\Queries\VisibleNotifications;
use App\Models\Member;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The cap is the center's whole world, not a page of it: OpenPNE 3 sliced its stored array to
 * `op_notification_limit` as it wrote, so badges and panel read the same items by construction. Bound
 * request-scoped, so the window is read once however many surfaces ask.
 */
class NotificationCenterWindow
{
    public const LIMIT = 20;

    /** @var array<int, Collection<int, DatabaseNotification>> */
    private array $cache = [];

    /** @return Collection<int, DatabaseNotification> */
    public function for(Member $viewer): Collection
    {
        // created_at is second-granular, so without the UUID tiebreak "the newest N" is not a defined
        // set and the badge request and the panel request could window differently.
        return $this->cache[$viewer->getKey()] ??= VisibleNotifications::apply($viewer->notifications())
            // The relation adds a latest() this would otherwise stack under.
            ->reorder('created_at', 'desc')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }
}
