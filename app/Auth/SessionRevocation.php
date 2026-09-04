<?php

namespace App\Auth;

use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Only the database driver keeps a queryable per-user session store; on other drivers the purge is
 * a no-op and the auth.session middleware is the best-effort fallback after a password change.
 * Tables are read from session.member_table / session.admin_table, never session.table, which is
 * pinned per request to the serving realm (docs/internals/sessions.md).
 */
final class SessionRevocation
{
    public static function revokeMember(Member $member, ?string $exceptSessionId = null): void
    {
        self::rotateRememberToken($member);
        self::purgeMemberSessions((int) $member->getAuthIdentifier(), $exceptSessionId);
    }

    public static function revokeAdmin(AdminUser $admin, ?string $exceptSessionId = null): void
    {
        self::rotateRememberToken($admin);
        self::purgeAdminSessions((int) $admin->getAuthIdentifier(), $exceptSessionId);
    }

    /**
     * Purge without rotating remember_token, for a caller that rotates it in its own save or whose
     * principal row is already gone.
     */
    public static function purgeMemberSessions(int $memberId, ?string $exceptSessionId = null): void
    {
        self::purge((string) config('session.member_table'), $memberId, $exceptSessionId);
    }

    public static function purgeAdminSessions(int $adminId, ?string $exceptSessionId = null): void
    {
        self::purge((string) config('session.admin_table'), $adminId, $exceptSessionId);
    }

    /**
     * A null token is already the end state, since retrieveByToken() needs a stored token to compare
     * against. Skipping it also keeps a ban working on an AI account, whose row the members CHECK
     * forbids any credential, so a rotation there would abort the whole freeze.
     */
    private static function rotateRememberToken(Model&Authenticatable $user): void
    {
        if ($user->getRememberToken() === null || $user->getRememberToken() === '') {
            return;
        }

        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    private static function purge(string $table, int $userId, ?string $exceptSessionId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table($table)
            ->where('user_id', $userId)
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
