<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use App\Support\SecurityLog;

/** Non-destructive, so no transaction and no primary-member guard. */
class AllowMemberLogin
{
    /** @param  Member  $actor  the administrator acting from the admin panel */
    public function __invoke(Member $member, Member $actor): void
    {
        $member->is_login_rejected = false;
        $member->save();

        SecurityLog::event('member.unbanned', [
            'member_id' => $member->getKey(),
            'admin_member_id' => $actor->getKey(),
        ]);
    }
}
