<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Freeze a member's login: set is_login_rejected and end every existing foothold. Admin-initiated;
 * the panel guard authorizes, so there is no per-actor check here — only the primary-member guard.
 */
class RejectMemberLogin
{
    public function __invoke(Member $member): void
    {
        // Defensive: the primary member (id 1) can never have login rejected. The admin UI also
        // hides and halts the action for id 1 (MemberResource::canDelete), so reaching here is a
        // programming error.
        if ((int) $member->getKey() === 1) {
            throw new RuntimeException('The primary member cannot have login rejected.');
        }

        // One transaction: the flag only blocks the NEXT login, so a frozen member's live sessions
        // and remember-me cookies must die with it — a ban that set the flag but failed the
        // revocation would look complete while the member stays signed in.
        DB::transaction(function () use ($member): void {
            // Direct assignment: is_login_rejected is outside the model's mass-assignable set.
            $member->is_login_rejected = true;
            $member->save();
            SessionRevocation::revokeMember($member);
        });

        SecurityLog::event('member.banned', [
            'member_id' => $member->getKey(),
            'admin_username' => auth('admin')->user()?->username,
        ]);
    }
}
