<?php

namespace App\Features\Member;

use App\Models\Member;

/**
 * Members see each other's last login by calendar day only, never the time (docs/internals/sessions.md,
 * "Key invariants"); an AI account never logs in, so it has no label rather than "not yet".
 */
class LastLoginLabel
{
    public static function for(Member $member): ?string
    {
        if ($member->isAiAccount()) {
            return null;
        }
        if ($member->last_login_at === null) {
            return __('Not logged in yet');
        }

        $days = (int) $member->last_login_at->copy()->startOfDay()->diffInDays(now()->startOfDay(), absolute: false);

        return match (true) {
            $days <= 0 => __('Today'),
            $days === 1 => __('Yesterday'),
            default => __(':count days ago', ['count' => $days]),
        };
    }
}
