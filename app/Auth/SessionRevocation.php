<?php

namespace App\Auth;

use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Drop a principal's authenticated footholds: rotate remember_token (invalidates
 * every "remember me" cookie) and, on the database session driver, delete their
 * server-side session rows. Other drivers keep no queryable per-user store; there
 * the auth.session middleware is the best-effort fallback where a password change
 * is involved.
 *
 * Table names come from the stable session.member_table / session.admin_table keys,
 * never session.table — that key is pinned per request to the serving realm
 * (UseAdminSessionStore), so reading it from an admin-realm action that revokes a
 * member (a ban) would target the wrong table.
 *
 * The purge-only methods exist for callers that already rotate the token in their
 * own persistence step (a reset's combined save, an email-change commit) or whose
 * principal row no longer exists (withdrawal).
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

    public static function purgeMemberSessions(int $memberId, ?string $exceptSessionId = null): void
    {
        self::purge((string) config('session.member_table'), $memberId, $exceptSessionId);
    }

    public static function purgeAdminSessions(int $adminId, ?string $exceptSessionId = null): void
    {
        self::purge((string) config('session.admin_table'), $adminId, $exceptSessionId);
    }

    private static function rotateRememberToken(Model&Authenticatable $user): void
    {
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
