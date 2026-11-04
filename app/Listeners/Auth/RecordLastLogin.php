<?php

namespace App\Listeners\Auth;

use App\Models\Member;
use Illuminate\Auth\Events\Login;

/**
 * Writes members.last_login_at at session start only — a password, passkey or two-factor login and a
 * remember-me restore — never per request, so the value lags a live session by up to the idle
 * lifetime (docs/internals/sessions.md, "Key invariants").
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        $member = $event->user;
        if ($event->guard !== 'member' || ! $member instanceof Member) {
            return;
        }

        Member::withoutTimestamps(fn () => $member->forceFill(['last_login_at' => now()])->saveQuietly());
    }
}
