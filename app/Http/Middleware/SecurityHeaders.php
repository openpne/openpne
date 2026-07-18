<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response security headers for every web response, member and admin alike. The Filament
 * panel keeps its own middleware stack (it does not inherit the `web` group), so this middleware is
 * registered there too — otherwise the admin pages, the highest-value clickjacking target, would ship
 * none of these.
 *
 * The CSP is only the clickjacking floor (`frame-ancestors`); it carries no content CSP
 * (script-src) — the Vite/Inertia bundle has no nonce/hash wiring — and that absence is what lets
 * the panel's inline Livewire/Alpine scripts run unrestricted. `Referrer-Policy` is set non-destructively so token
 * screens can tighten it to `no-referrer` (NoReferrer). HSTS is emitted only under force_https, so a
 * plain-HTTP dev host is not pinned to a scheme it cannot serve. Cross-Origin-Resource-Policy is
 * deliberately omitted: web-public avatars and banners are served for cross-origin embedding.
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
