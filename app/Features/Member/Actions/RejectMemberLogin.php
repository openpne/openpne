<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Features\AiAccount\AiAccountTokens;
use App\Features\AiAccount\MemberTokens;
use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The caller authorizes; nothing here checks the actor. */
class RejectMemberLogin
{
    use SyncsCallerInstance;

    /** @throws AdministratorRoleHeld */
    public function __invoke(Member $member): void
    {
        // Defensive: the admin UI hides the action for id 1, so reaching here is a programming error.
        if ((int) $member->getKey() === 1) {
            throw new RuntimeException('The primary member cannot have login rejected.');
        }

        // One transaction: the flag only blocks the next login, so live sessions and tokens must die
        // with it.
        $banned = DB::transaction(function () use ($member): bool {
            // Re-read under the row's lock, which GrantAdministrator takes too, so a grant committed since the caller read the member is seen.
            $locked = Member::whereKey($member->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return false;
            }

            if ($locked->isAdministrator()) {
                throw new AdministratorRoleHeld;
            }

            // Direct assignment: is_login_rejected is outside the model's mass-assignable set.
            $locked->is_login_rejected = true;
            $locked->save();
            $this->syncCaller($member, $locked);
            SessionRevocation::revokeMember($member);
            // Every token, not just the MCP ones: a ban ends every foothold, whatever minted it.
            MemberTokens::deleteAll($member);

            // Only the AI account's tokens: it has no session to end, and banning the account itself
            // would need a symmetric un-ban.
            AiAccountTokens::revokeOwnedBy($member);

            return true;
        });

        if (! $banned) {
            return;
        }

        SecurityLog::event('member.banned', [
            'member_id' => $member->getKey(),
            'admin_username' => auth('admin')->user()?->username,
        ]);
    }
}
