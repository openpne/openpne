<?php

namespace App\Auth;

use App\Models\AdminPasswordSetupToken;
use App\Models\AdminUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * The one writer of an administrator's password and setup link: every operation locks the
 * administrator row before the link row, and a password change voids the unused link and the
 * administrator's sessions in the same commit (docs/internals/security.md, "Administrator password links").
 */
final class AdminPasswords
{
    public const TOKEN_PATTERN = '/\A[A-Za-z0-9]{40}\z/';

    /**
     * Replaces any earlier link and leaves the current password and sessions as they are.
     *
     * @return array{string, Carbon} the raw token and when the link expires
     */
    public function issueSetupLink(AdminUser $admin, int $minutes): array
    {
        $raw = Str::random(40);
        // Whole seconds, as the column stores them, so the printed expiry is the stored one.
        $now = now()->startOfSecond();
        $expiresAt = $now->copy()->addMinutes($minutes);

        DB::transaction(function () use ($admin, $raw, $now, $expiresAt): void {
            $this->lock($admin);
            AdminPasswordSetupToken::upsert(
                [['admin_user_id' => $admin->getKey(), 'token' => hash('sha256', $raw), 'expires_at' => $expiresAt, 'created_at' => $now]],
                ['admin_user_id'],
                ['token', 'expires_at', 'created_at'],
            );
        });

        return [$raw, $expiresAt];
    }

    public function setupLinkOwner(#[SensitiveParameter] string $token): ?AdminUser
    {
        $link = $this->liveLink($token);

        return $link === null ? null : AdminUser::find($link->admin_user_id);
    }

    /**
     * Null when the link was not live at commit time, with nothing written.
     */
    public function consumeSetupLink(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): ?AdminUser
    {
        $link = $this->liveLink($token);
        if ($link === null) {
            return null;
        }

        return DB::transaction(function () use ($link, $password): ?AdminUser {
            $admin = AdminUser::whereKey($link->admin_user_id)->lockForUpdate()->first();
            if ($admin === null) {
                return null;
            }

            $consumed = AdminPasswordSetupToken::whereKey($admin->getKey())
                ->where('token', $link->token)
                ->where('expires_at', '>', now())
                ->delete();
            if ($consumed !== 1) {
                return null;
            }

            $this->write($admin, $password);
            SessionRevocation::revokeAdmin($admin);

            return $admin;
        });
    }

    /**
     * Ends every session of the administrator except $keepSessionId, and leaves $admin holding the new hash.
     */
    public function change(AdminUser $admin, #[SensitiveParameter] string $password, ?string $keepSessionId = null): void
    {
        $locked = DB::transaction(function () use ($admin, $password, $keepSessionId): AdminUser {
            $locked = $this->lock($admin);
            AdminPasswordSetupToken::whereKey($locked->getKey())->delete();
            $this->write($locked, $password);
            SessionRevocation::revokeAdmin($locked, $keepSessionId);

            return $locked;
        });

        $admin->setRawAttributes($locked->getAttributes(), sync: true);
    }

    private function liveLink(string $token): ?AdminPasswordSetupToken
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        return AdminPasswordSetupToken::where('token', hash('sha256', $token))->where('expires_at', '>', now())->first();
    }

    private function lock(AdminUser $admin): AdminUser
    {
        return AdminUser::whereKey($admin->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Hashed here rather than by the cast, which stores as-is a value already shaped like a hash. */
    private function write(AdminUser $admin, #[SensitiveParameter] string $password): void
    {
        $admin->forceFill(['password' => Hash::make($password)])->save();
    }
}
