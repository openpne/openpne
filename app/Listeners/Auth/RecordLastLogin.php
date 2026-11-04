<?php

namespace App\Listeners\Auth;

use App\Models\Member;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * members.last_login_at is bookkeeping: a failed write is reported, never surfaced, so a login still
 * completes (docs/internals/sessions.md, "Key invariants").
 */
class RecordLastLogin
{
    public function handleLogin(Login $event): void
    {
        $this->record($event->guard, $event->user, always: true);
    }

    /** A session kept alive by polling never logs in again, so a live member is refreshed on each new calendar day. */
    public function handleAuthenticated(Authenticated $event): void
    {
        $this->record($event->guard, $event->user, always: false);
    }

    private function record(string $guard, ?Authenticatable $user, bool $always): void
    {
        if ($guard !== 'member' || ! $user instanceof Member) {
            return;
        }
        if (! $always && $user->last_login_at !== null && $user->last_login_at->gte(today())) {
            return;
        }

        $now = now();
        rescue(function () use ($user, $now): void {
            Member::whereKey($user->getKey())->toBase()->update(['last_login_at' => $now]);
            $user->setAttribute('last_login_at', $now)->syncOriginalAttribute('last_login_at');
        });
    }
}
