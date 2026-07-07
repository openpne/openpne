<?php

namespace App\Features\Home;

use App\Features\Friend\Queries\CountReceivedFriendRequests;
use App\Features\Message\Queries\CountUnreadMessages;
use App\Features\Notifications\Queries\CountUnreadNotifications;
use App\Models\Member;

/**
 * The shell's badge counts, aggregated from each feature's own count query. Both the nav badges
 * (shared on every page via HandleInertiaRequests) and the dashboard notices read this, so it is
 * bound request-scoped and memoizes per member — the underlying queries run once per request no
 * matter how many surfaces ask.
 *
 * friendRequests/unreadMessages are layer-1 counts (derived live from each source's own truth,
 * dropped by acting on the item); notifications is layer 3's own unread-row count (read_at-driven,
 * dropped by reading the feed). The two never feed each other — see docs/internals/notifications.md.
 */
class UnreadCounts
{
    /** @var array<int, array{friendRequests: int, unreadMessages: int, notifications: int}> */
    private array $cache = [];

    public function __construct(
        private readonly CountReceivedFriendRequests $friendRequests,
        private readonly CountUnreadMessages $unreadMessages,
        private readonly CountUnreadNotifications $notifications,
    ) {}

    /** @return array{friendRequests: int, unreadMessages: int, notifications: int} */
    public function for(Member $viewer): array
    {
        return $this->cache[$viewer->getKey()] ??= [
            'friendRequests' => ($this->friendRequests)($viewer),
            'unreadMessages' => ($this->unreadMessages)($viewer),
            'notifications' => ($this->notifications)($viewer),
        ];
    }
}
