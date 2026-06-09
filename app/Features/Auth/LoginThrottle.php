<?php

namespace App\Features\Auth;

use Illuminate\Support\Facades\Cache;

/**
 * Counts failed logins per client IP and decides when the login form must carry a CAPTCHA. This is a
 * soft escalation, not a lockout: a tripped IP is asked to solve a challenge, never blocked, so it
 * cannot be used to lock a victim out. It complements the per-(email, IP) rate limiter, which a single
 * IP guessing across many addresses slips past — each address is a fresh bucket, but the failures all
 * land on the same IP here.
 */
class LoginThrottle
{
    public function challengeRequired(string $ip): bool
    {
        return (int) Cache::get($this->key($ip), 0) >= $this->threshold();
    }

    public function recordFailure(string $ip): void
    {
        $key = $this->key($ip);

        // Seed with the window TTL on the first failure; later failures within the window only bump it.
        if (! Cache::add($key, 1, now()->addMinutes($this->windowMinutes()))) {
            Cache::increment($key);
        }
    }

    public function clear(string $ip): void
    {
        Cache::forget($this->key($ip));
    }

    private function key(string $ip): string
    {
        return 'login:fails:'.$ip;
    }

    private function threshold(): int
    {
        return (int) config('openpne.login.captcha_after_failures');
    }

    private function windowMinutes(): int
    {
        return (int) config('openpne.login.failure_window_minutes');
    }
}
