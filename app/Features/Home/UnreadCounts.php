<?php

namespace App\Features\Home;

use App\Features\DirectMessage\Queries\CountUnreadConversations;
use App\Features\Friend\Queries\CountReceivedFriendRequests;
use App\Features\GroupTalk\Queries\CountGroupsWithUnreadTalk;
use App\Features\Notifications\Queries\CountUnreadNotifications;
use App\Models\Member;
use App\Support\Feature;

/**
 * The shell's badge counts, bound request-scoped and memoized per member so the underlying queries
 * run once however many surfaces ask. friendRequests, unreadMessages and groupTalks are layer-1
 * counts while notifications is layer 3's own unread-row count, and the two never feed each other
 * (docs/internals/notifications.md, "The three layers").
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
            // Conversations with something new, not messages.
            'unreadMessages' => Feature::DirectMessage->enabled() ? ($this->unreadMessages)($viewer) : 0,
            'notifications' => ($this->notifications)($viewer),
            // Groups with something new, not messages: it falls by reading the conversation rather
            // than by dismissing anything.
            'groupTalks' => Feature::GroupTalk->enabled() ? ($this->groupTalks)($viewer) : 0,
        ];
    }
}
