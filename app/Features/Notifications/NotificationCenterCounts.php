<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Models\Member;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Counted off the very rows the panel lists rather than off UnreadCounts or the whole unread table, so a
 * badge can never stand over rows the panel does not show (docs/internals/notifications.md, The three
 * layers).
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
