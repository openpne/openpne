<?php

namespace App\Features\Home;

use App\Features\DirectMessage\Queries\CountUnreadConversations;
use App\Features\Friend\Queries\CountReceivedFriendRequests;
use App\Features\GroupTalk\Queries\CountGroupsWithUnreadTalk;
use App\Features\Notifications\Queries\CountUnreadNotifications;
use App\Models\Member;
use App\Support\Feature;

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
    /** @var array<int, array{friendRequests: int, unreadMessages: int, notifications: int, groupTalks: int}> */
    private array $cache = [];

    public function __construct(
        private readonly CountReceivedFriendRequests $friendRequests,
        private readonly CountUnreadConversations $unreadMessages,
        private readonly CountUnreadNotifications $notifications,
        private readonly CountGroupsWithUnreadTalk $groupTalks,
    ) {}

    /** @return array{friendRequests: int, unreadMessages: int, notifications: int, groupTalks: int} */
    public function for(Member $viewer): array
    {
        // A switched-off unit reports zero without querying: there is no screen left to send the
        // member to, so a badge would only point at a 404.
        return $this->cache[$viewer->getKey()] ??= [
            'friendRequests' => Feature::Friend->enabled() ? ($this->friendRequests)($viewer) : 0,
            // Conversations with something new, not messages — see CountUnreadConversations, and the
            // groups badge below, which counts rooms for the same reason.
            'unreadMessages' => Feature::DirectMessage->enabled() ? ($this->unreadMessages)($viewer) : 0,
            'notifications' => ($this->notifications)($viewer),
            // Groups with something new, not messages — see CountGroupsWithUnreadTalk. Layer-1 like
            // the two above: it falls by reading the conversation, not by dismissing anything.
            'groupTalks' => Feature::GroupTalk->enabled() ? ($this->groupTalks)($viewer) : 0,
        ];
    }
}
