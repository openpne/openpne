<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spelled out rather than `throttle:` because the framework's ThrottleRequests sorts below
 * Authenticate in the priority list and would run only once a credential is accepted; this one is
 * outside the list and keeps the first slot. Keyed by IP alone, so behind a proxy TRUSTED_PROXIES
 * must be set or every caller shares one bucket.
 */
class ThrottleMcpByIp
{
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $perMinute = max(0, (int) config('openpne.throttle.mcp_ip'));

        if ($perMinute === 0) {
            return $next($request);
        }

        $key = 'mcp-ip|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $retryAfter = RateLimiter::availableIn($key);

            // The framework's own 429, so the security log's throttle observability and the
            // Retry-After contract are the ones every other limited route already answers with.
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $perMinute,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->getTimestamp(),
            ]);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }
}
