<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-IP cap on the MCP endpoint, spelled out here rather than as `throttle:` because the
 * framework's ThrottleRequests sits BELOW Authenticate in the middleware priority list: a named
 * limiter attached to the same route runs only once a credential has been accepted, so it bounds a
 * legitimate client and does nothing at all about someone spraying tokens at the door. This one is
 * outside the priority list, so it keeps the slot the route gives it — first.
 *
 * Keyed by IP alone, deliberately: before authentication there is nothing else to key by. Behind a
 * proxy that means TRUSTED_PROXIES must be set, or every caller shares one bucket
 * (see bootstrap/app.php).
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
