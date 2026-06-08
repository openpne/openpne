<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response security headers for every web response. The CSP is only the clickjacking floor
 * (`frame-ancestors`); a content CSP (script-src) is deferred until the Vite/Inertia bundle gets its
 * own nonce/hash work. `Referrer-Policy` is set non-destructively so token screens can tighten it to
 * `no-referrer` (NoReferrer). HSTS is emitted only under force_https, so a plain-HTTP dev host is not
 * pinned to a scheme it cannot serve.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');

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
