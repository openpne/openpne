<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\Passport;

/**
 * Rows the OAuth flow no longer needs: revoked or long-expired codes and tokens (an access token
 * whose refresh token is still usable stays), and clients that registered but never obtained a
 * code — a client that was used keeps its id, which the other side still holds.
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

        $removed += Passport::client()->newQuery()
            ->where('created_at', '<', now()->subDays(self::UNUSED_CLIENT_DAYS))
            ->whereDoesntHave('tokens')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('oauth_auth_codes')->whereColumn('oauth_auth_codes.client_id', 'oauth_clients.id'))
            ->delete();

        return $removed;
    }
}
