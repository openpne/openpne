<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use App\Support\SecurityLog;

/**
 * Lift a member's login freeze — the inverse of RejectMemberLogin. Non-destructive, so no
 * transaction (a single save) and no primary-member guard.
 */
class AllowMemberLogin
{
    public function __invoke(Member $member): void
    {
        $member->is_login_rejected = false;
        $member->save();

        SecurityLog::event('member.unbanned', [
            'member_id' => $member->getKey(),
            'admin_username' => auth('admin')->user()?->username,
        ]);
    }
}
