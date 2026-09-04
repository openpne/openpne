<?php

namespace App\Features\Auth;

use Illuminate\Support\Facades\Cache;

/**
 * A tripped IP is asked for a CAPTCHA, never blocked, so this cannot be used to lock a victim out.
 * It is keyed by IP alone because the per-(email, IP) login limiter gives one IP a fresh bucket
 * for every address it tries.
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
