<?php

namespace App\Http\Middleware;

use App\Support\FileDeliveryRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drops the session cookies from a file response that declares itself publicly cacheable.
 *
 * A shared cache will not store a response carrying Set-Cookie, so without this the `public`
 * declaration is inert — and a cache configured to store it anyway would hand one visitor
 * another's session. File delivery sits in the `web` group because the private classes need the
 * session to identify the viewer, so the cookies are attached on the way out whether the file
 * turned out to be public or not.
 *
 * This has to be the outermost middleware of the group: StartSession adds the session cookie and
 * AddQueuedCookiesToResponse the queued ones while the response unwinds, so anything inside them
 * sees a response they will still add to.
 *
 * Nothing is lost by dropping them here. The session lives server-side and every page response
 * refreshes the cookie; these routes normally serve sub-resources of a page the viewer already
 * loaded, and retrieving one directly does not need the session refreshed.
 */
class RemoveCookiesFromPublicFileResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The response's own directive is the authority, not the route: the same route serves
        // both private and publicly cacheable files, and only the controller knows which.
        if ($response->headers->hasCacheControlDirective('public')
            && FileDeliveryRoutes::matches($request->route())) {
            $response->headers->remove('Set-Cookie');
        }

        return $response;
    }
}
