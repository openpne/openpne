<?php

namespace App\Http\Middleware;

use App\Support\CookiePrefix;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global and ahead of every group, so the prefixed name is in place before anything builds the session
 * driver (docs/internals/sessions.md, "Cookie-name prefix").
 */
class PrefixSessionCookie
{
    private readonly string $base;

    // Bound as a container singleton so the unprefixed name is captured once per process, before the
    // first request's pin replaces it.
    public function __construct()
    {
        $this->base = (string) config('session.cookie');
    }

    public function handle(Request $request, Closure $next): Response
    {
        config(['session.cookie' => CookiePrefix::apply($this->base)]);

        return $next($request);
    }
}
