<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the request as an XHR so the session does not record it as the "previous URL"
 * (StartSession::storeCurrentUrl skips ajax requests). The ALTCHA widget fetches the challenge with
 * a plain GET; without this it becomes the back-navigation target, and a later redirect()->back()
 * (a failed login, a validation error) lands on that raw-JSON endpoint — navigating the Classic page
 * to JSON and feeding the Inertia client a non-Inertia response.
 *
 * App\Http\Middleware\StartSession rules out non-navigation requests generally; this stays for the
 * clients it cannot classify (no Fetch Metadata), and is not the pattern to reach for per route.
 */
class AsBackgroundFetch
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        return $next($request);
    }
}
