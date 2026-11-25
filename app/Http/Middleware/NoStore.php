<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For a screen whose URL or form carries a secret: no cache stores or rewrites the response, and the
 * session does not record the URL as the previous one (docs/internals/security.md, "Response headers").
 */
class NoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        StartSession::keepUrlOut($request);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, no-transform, private');

        return $response;
    }
}
