<?php

namespace App\Features\Member\Actions;

use App\Features\Member\AdministratorLock;
use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Refused for an AI account and for a member being withdrawn or with login rejected, each read under
 * the member row's lock, which WithdrawMember and RejectMemberLogin take to refuse an administrator
 * (docs/internals/administrators.md, "Who can hold the role").
 */
class GrantAdministrator
{
    use SyncsCallerInstance;

    /** @param  Member|null  $actor  the administrator granting from the admin panel; null from the command line */
    public function __invoke(Member $member, ?Member $actor = null): AdministratorRoleResult
    {
        $result = DB::transaction(function () use ($member, $actor): AdministratorRoleResult {
            $administrators = AdministratorLock::hold();
            if ($actor !== null && ! $administrators->contains((int) $actor->getKey())) {
                return AdministratorRoleResult::ActorNotAdministrator;
            }

            $locked = Member::whereKey($member->getKey())->lockForUpdate()->first();

            $refusal = match (true) {
                $locked === null => AdministratorRoleResult::MemberGone,
                $locked->isAdministrator() => AdministratorRoleResult::AlreadyAdministrator,
                $locked->isAiAccount() => AdministratorRoleResult::AiAccount,
                $locked->withdrawal_started_at !== null => AdministratorRoleResult::Withdrawing,
                (bool) $locked->is_login_rejected => AdministratorRoleResult::LoginRejected,
                default => null,
            };
            if ($refusal !== null) {
                return $refusal;
            }

            $locked->forceFill(['admin_granted_at' => now(), 'admin_generation' => $locked->admin_generation + 1])->save();
            $this->syncCaller($member, $locked);

            return AdministratorRoleResult::Granted;
        }, attempts: 3);

        if ($result === AdministratorRoleResult::Granted) {
            SecurityLog::event('admin.granted', ['member_id' => (int) $member->getKey(), 'actor' => $actor === null ? 'cli' : (int) $actor->getKey()]);
        }

        return $result;
    }
}
