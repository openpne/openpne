<?php

namespace App\Auth;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Only the database driver keeps a queryable per-user session store; on other drivers the purge is
 * a no-op and the auth.session middleware is the best-effort fallback after a password change.
 */
final class SessionRevocation
{
    public static function revokeMember(Member $member, ?string $exceptSessionId = null): void
    {
        self::purgeMemberSessions((int) $member->getAuthIdentifier(), $exceptSessionId);
    }

    public static function purgeMemberSessions(int $memberId, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table((string) config('session.table'))
            ->where('user_id', $memberId)
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
