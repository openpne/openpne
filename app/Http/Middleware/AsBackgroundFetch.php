<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the request as an XHR so the session never records it as the previous URL, which would send
 * a later redirect()->back() to this raw JSON endpoint. StartSession already refuses non-page
 * responses, so this is only the request-side statement of that rule, not a pattern for new routes.
 */
class AsBackgroundFetch
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        return $next($request);
    }
}
