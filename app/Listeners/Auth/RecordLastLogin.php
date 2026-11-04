<?php

namespace App\Listeners\Auth;

use App\Models\Member;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * members.last_login_at is bookkeeping for site operators: a failed write is reported, never surfaced,
 * so a login still completes (docs/internals/sessions.md, "Key invariants").
 */
class RecordLastLogin
{
    public function handleLogin(Login $event): void
    {
        $this->record($event->guard, $event->user, always: true);
    }

    /** A session kept alive by polling never logs in again, so a live member is refreshed once a day. */
    public function handleAuthenticated(Authenticated $event): void
    {
        $this->record($event->guard, $event->user, always: false);
    }

    private function record(string $guard, ?Authenticatable $user, bool $always): void
    {
        if ($guard !== 'member' || ! $user instanceof Member) {
            return;
        }
        if (! $always && $user->last_login_at !== null && $user->last_login_at->gt(now()->subDay())) {
            return;
        }

        rescue(fn () => Member::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->saveQuietly()));
    }
}
