<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\Passport;

/**
 * Rows the OAuth flow no longer needs: revoked or long-expired codes and tokens (an access token
 * whose refresh token is still usable stays), and clients that registered but never obtained a
 * code — a client once used keeps its id (`used_at`), which the other side still holds.
 */
final class PruneOAuth
{
    private const KEEP_EXPIRED_HOURS = 168;

    private const UNUSED_CLIENT_DAYS = 7;

    /** @return int rows removed */
    public static function run(): int
    {
        $cutoff = now()->subHours(self::KEEP_EXPIRED_HOURS);
        $stale = fn (Builder $q): Builder => $q->where('revoked', true)->orWhere('expires_at', '<', $cutoff);

        $removed = Passport::authCode()->newQuery()->where($stale)->delete();
        $removed += Passport::refreshToken()->newQuery()->where($stale)->delete();
        $removed += Passport::token()->newQuery()->where($stale)
            ->whereDoesntHave('refreshToken', fn (Builder $r) => $r->where('revoked', false)->where('expires_at', '>', now()))
            ->delete();

        return $removed + Passport::client()->newQuery()
            ->whereNull('used_at')
            ->where('created_at', '<', now()->subDays(self::UNUSED_CLIENT_DAYS))
            ->whereDoesntHave('tokens')
            ->delete();
    }
}
