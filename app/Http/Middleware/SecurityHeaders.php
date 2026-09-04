<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered in the `web` group and again on the Filament panel stack, which does not inherit `web`.
 * `Referrer-Policy` and the CSP are set only when absent so a token screen can tighten them
 * (NoReferrer); what is deliberately not set is in docs/internals/security.md.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');

        // No feature needs the camera, microphone, or geolocation; deny them outright.
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Isolate the browsing context: the app opens no cross-origin popups, so nothing
        // relies on window.opener, and same-origin blocks opener-based attacks.
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'");
        }

        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        if (config('openpne.security.force_https')) {
            // No includeSubDomains: a self-hoster on an apex domain must not have sibling
            // services on other subdomains pinned to HTTPS for a year as a side effect.
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
