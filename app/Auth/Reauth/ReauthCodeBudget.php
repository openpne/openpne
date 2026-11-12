<?php

namespace App\Auth\Reauth;

use App\Models\Member;
use App\Notifications\Member\MfaCodesPausedNotification;
use App\Support\SecurityLog;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** See docs/internals/security.md, "Member re-authentication". */
class ReauthCodeBudget
{
    private const PER_MINUTE = 5;

    public const MAX_CONSECUTIVE = 100;

    private const PAUSE_SECONDS = 24 * 60 * 60;

    /**
     * Call before the code is compared and outside any transaction: the database cache store shares
     * the default connection, so a failed verification's rollback would take the count with it.
     */
    public function reserve(Member $member, Session $session): void
    {
        // Checked first, so a burst this refuses never brings the pause closer.
        if (self::count('reauth-code|'.$member->getKey().'|'.hash('sha256', $session->getId()), 60) > self::PER_MINUTE) {
            throw ValidationException::withMessages([
                'code' => __('Too many authentication codes were tried. Please wait a minute and try again.'),
            ]);
        }

        $failures = self::count(self::pauseKey($member), self::PAUSE_SECONDS);

        if ($failures <= self::MAX_CONSECUTIVE) {
            return;
        }

        if ($failures === self::MAX_CONSECUTIVE + 1) {
            // Logged before the fallible enqueue, which must not suppress the audit record.
            SecurityLog::event('mfa.codes_paused', ['guard' => 'member', 'member_id' => $member->getKey()]);
            $member->notify(new MfaCodesPausedNotification($member->locale ?? app()->getLocale()));
        }

        throw ValidationException::withMessages([
            'code' => __('Authentication codes are paused for your account after too many wrong ones. Use a recovery code or a passkey, or try again tomorrow.'),
        ]);
    }

    public function clear(Member $member): void
    {
        Cache::forget(self::pauseKey($member));
    }

    /**
     * Not `RateLimiter::hit`, whose first-hit correction can write a counter back to 1 over hits
     * made in parallel. The window runs from the first hit, and a count that cannot be taken refuses.
     */
    private static function count(string $key, int $seconds): int
    {
        Cache::add($key, 0, $seconds);
        $count = Cache::increment($key);

        // The entry expired between the two calls.
        if (! is_int($count)) {
            Cache::add($key, 0, $seconds);
            $count = Cache::increment($key);
        }

        return is_int($count) ? $count : PHP_INT_MAX;
    }

    private static function pauseKey(Member $member): string
    {
        return 'reauth-code-failures|'.$member->getKey();
    }
}
