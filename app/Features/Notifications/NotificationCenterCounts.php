<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Models\Member;

/**
 * The sprite's three badge numbers, counted off the rows the panel itself lists.
 *
 * Deliberately not [`UnreadCounts`](../Home/UnreadCounts.php), which mixes layer-1 truth (what is
 * unread in the mailbox, how many requests are pending) with layer 3: a badge heading a panel has
 * to count what is in that panel, or opting a kind out of the web channel leaves a badge standing
 * over an empty list and the third badge re-counts the first two. The home cautions keep asking
 * layer 1, and the two are not reconciled — OpenPNE 3's diverged the same way, its badges coming
 * from the event store while its cautions ran their own queries.
 *
 * Bound request-scoped: the shell asks on every page, so memoize per member.
 */
class NotificationCenterCounts
{
    /** @var array<int, array<string, int>> */
    private array $cache = [];

    /** @return array<string, int> keyed by NotificationCenterCategory value */
    public function for(Member $viewer): array
    {
        return $this->cache[$viewer->getKey()] ??= $this->count($viewer);
    }

    /** @return array<string, int> */
    protected function count(Member $viewer): array
    {
        $total = $viewer->unreadNotifications()->count();
        $message = $viewer->unreadNotifications()->where('data->kind', 'message_received')->count();
        $friend = $viewer->unreadNotifications()->where('data->kind', 'friend_requested')->count();

        return [
            NotificationCenterCategory::Message->value => $message,
            NotificationCenterCategory::Friend->value => $friend,
            // What is left rather than what matches: a row with no kind, or a kind added after this
            // was written, still reaches a badge instead of disappearing from all three.
            NotificationCenterCategory::Other->value => max(0, $total - $message - $friend),
        ];
    }
}
