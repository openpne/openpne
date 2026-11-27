<?php

namespace App\Features\Member\Actions;

/** What GrantAdministrator or RevokeAdministrator did, or why it wrote nothing. */
enum AdministratorRoleResult
{
    case Granted;
    case Revoked;
    case AlreadyAdministrator;
    case NotAdministrator;
    case MemberGone;
    case AiAccount;
    case Withdrawing;
    case LoginRejected;

    /** Revoking would leave no administrator. */
    case LastAdministrator;

    /** An administrator acting from the admin panel tried to revoke their own role. */
    case OwnRole;

    /** The acting administrator's own role was revoked before this change took its locks. */
    case ActorNotAdministrator;

    /** Whether the command that asked should exit 0: the role is now as asked. */
    public function succeeded(): bool
    {
        return in_array($this, [self::Granted, self::Revoked, self::AlreadyAdministrator, self::NotAdministrator], true);
    }
}
