<?php

namespace App\Features\Home;

use App\Features\Friend\Queries\CountReceivedFriendRequests;
use App\Features\Message\Queries\CountUnreadMessages;
use App\Models\Member;

/**
 * The shell's "needs your attention" counts, aggregated from each feature's own count query. Both
 * the nav badges (shared on every page via HandleInertiaRequests) and the dashboard notices read
 * this, so it is bound request-scoped and memoizes per member — the underlying queries run once per
 * request no matter how many surfaces ask.
 *
 * Layer 1 of the notification model: counts derived live from each source's own truth (a pending
 * friend_requests row, an unread receipt), with no separate notification table and no seen state.
 */
class UnreadCounts
{
    /** @var array<int, array{friendRequests: int, unreadMessages: int}> */
    private array $cache = [];

    public function __construct(
        private readonly CountReceivedFriendRequests $friendRequests,
        private readonly CountUnreadMessages $unreadMessages,
    ) {}

    /** @return array{friendRequests: int, unreadMessages: int} */
    public function for(Member $viewer): array
    {
        return $this->cache[$viewer->getKey()] ??= [
            'friendRequests' => ($this->friendRequests)($viewer),
            'unreadMessages' => ($this->unreadMessages)($viewer),
        ];
    }
}
