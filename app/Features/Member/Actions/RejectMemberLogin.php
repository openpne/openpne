<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Features\AiAccount\AiAccountTokens;
use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The caller authorizes; nothing here checks the actor. */
class RejectMemberLogin
{
    public function __invoke(Member $member): void
    {
        // Defensive: the admin UI hides the action for id 1, so reaching here is a programming error.
        if ((int) $member->getKey() === 1) {
            throw new RuntimeException('The primary member cannot have login rejected.');
        }

        // One transaction: the flag only blocks the next login, so live sessions, remember-me cookies
        // and tokens must die with it.
        DB::transaction(function () use ($member): void {
            // Direct assignment: is_login_rejected is outside the model's mass-assignable set.
            $member->is_login_rejected = true;
            $member->save();
            SessionRevocation::revokeMember($member);
            // Every token, not just the MCP ones: a ban ends every foothold, whatever minted it.
            $member->tokens()->delete();

            // Only the AI account's tokens: it has no session to end, and banning the account itself
            // would need a symmetric un-ban.
            AiAccountTokens::revokeOwnedBy($member);
        });

        SecurityLog::event('member.banned', [
            'member_id' => $member->getKey(),
            'admin_username' => auth('admin')->user()?->username,
        ]);
    }
}
