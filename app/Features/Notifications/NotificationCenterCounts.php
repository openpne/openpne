<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Models\Member;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The sprite's three badge numbers, counted off the very rows the panel lists —
 * [`NotificationCenterWindow`](NotificationCenterWindow.php), unread only, split by category.
 *
 * Deliberately not [`UnreadCounts`](../Home/UnreadCounts.php), which mixes layer-1 truth (what is
 * unread in the mailbox, how many requests are pending) with layer 3: a badge heading a panel has
 * to count what is in that panel, or opting a kind out of the web channel leaves a badge standing
 * over an empty list and the third badge re-counts the first two. Nor the whole unread table, which
 * would badge events older than the window the panel can show. The home cautions keep asking layer
 * 1, and the two are not reconciled — OpenPNE 3's diverged the same way, its badges coming from the
 * capped event store while its cautions ran their own queries.
 */
class NotificationCenterCounts
{
    public function __construct(private readonly NotificationCenterWindow $window) {}

    /** @return array<string, int> keyed by NotificationCenterCategory value */
    public function for(Member $viewer): array
    {
        $counts = array_fill_keys(array_column(NotificationCenterCategory::cases(), 'value'), 0);

        foreach ($this->window->for($viewer) as $row) {
            if ($row->read_at !== null) {
                continue;
            }

            $counts[NotificationCenterCategory::for($this->kindOf($row))->value]++;
        }

        return $counts;
    }

    private function kindOf(DatabaseNotification $row): ?string
    {
        $kind = $row->data['kind'] ?? null;

        return is_string($kind) ? $kind : null;
    }
}
