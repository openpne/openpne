<?php

namespace App\Features\Member\Actions;

use App\Features\Member\AdministratorLock;
use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * The remaining administrators are counted from the locked rows, so two revocations at once cannot
 * both see another administrator left (docs/internals/administrators.md, "The last administrator").
 */
class RevokeAdministrator
{
    use SyncsCallerInstance;

    /** @param  Member|null  $actor  the administrator revoking from the admin panel; null from the command line */
    public function __invoke(Member $member, ?Member $actor = null): AdministratorRoleResult
    {
        $result = DB::transaction(function () use ($member, $actor): AdministratorRoleResult {
            $administrators = AdministratorLock::hold();
            if ($actor !== null && ! $administrators->contains((int) $actor->getKey())) {
                return AdministratorRoleResult::ActorNotAdministrator;
            }
            if ($actor !== null && (int) $actor->getKey() === (int) $member->getKey()) {
                return AdministratorRoleResult::OwnRole;
            }

            $locked = Member::whereKey($member->getKey())->lockForUpdate()->first();

            $refusal = match (true) {
                $locked === null => AdministratorRoleResult::MemberGone,
                ! $locked->isAdministrator() => AdministratorRoleResult::NotAdministrator,
                $administrators->count() <= 1 => AdministratorRoleResult::LastAdministrator,
                default => null,
            };
            if ($refusal !== null) {
                return $refusal;
            }

            $locked->forceFill(['admin_granted_at' => null, 'admin_generation' => $locked->admin_generation + 1])->save();
            $this->syncCaller($member, $locked);

            return AdministratorRoleResult::Revoked;
        }, attempts: 3);

        if ($result === AdministratorRoleResult::Revoked) {
            SecurityLog::event('admin.revoked', ['member_id' => (int) $member->getKey(), 'actor' => $actor === null ? 'cli' : (int) $actor->getKey()]);
        }

        return $result;
    }
}
