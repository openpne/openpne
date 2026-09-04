<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shared cache will not store a response carrying Set-Cookie, and one configured to store it anyway
 * would hand one visitor another's session. Must be outermost in the `web` group: StartSession and
 * AddQueuedCookiesToResponse add cookies while the response unwinds, so anything inside them sees a
 * response they will still add to.
 */
class RemoveCookiesFromPublicFileResponses
{
    /** The delivery routes. Named rather than sniffed so a new route is an explicit decision. */
    private const ROUTES = ['file.show', 'image.show', 'banner.image', 'file.public'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The response's own directive is the authority, not the route: the same route serves
        // both private and publicly cacheable files, and only the controller knows which.
        if ($response->headers->hasCacheControlDirective('public')
            && in_array($request->route()?->getName(), self::ROUTES, true)) {
            $response->headers->remove('Set-Cookie');
        }

        return $response;
    }
}
