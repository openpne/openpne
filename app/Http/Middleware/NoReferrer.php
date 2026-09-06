<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces `Referrer-Policy: no-referrer` on a screen whose URL or form carries a secret, closing the
 * same-origin Referer channel the SecurityHeaders baseline leaves open (docs/internals/security.md,
 * "Response headers").
 */
class NoReferrer
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
