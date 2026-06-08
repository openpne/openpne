<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces `Referrer-Policy: no-referrer` on screens that carry a secret in the URL or form
 * (login, password reset, registration), so a click-out or third-party asset cannot leak the
 * reset/registration token via the Referer header. Overrides SecurityHeaders' softer default.
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
