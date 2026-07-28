<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * OpenPNE 3's `default/error` screen: the statuses a member can walk into render inside the Classic
 * shell, the way OpenPNE 3 decorated that action with the layout — a bare framework error page
 * drops the site's header, nav and skin, which for a Classic install reads as the site being gone.
 *
 * Only 403/404/419. A 5xx keeps the framework's dependency-free page: the shell it would render in
 * reads the database (settings, navigation, banners), which is exactly what tends to be broken.
 */
final class ClassicErrorPage
{
    private const STATUSES = [403, 404, 419];

    /**
     * The Classic rendering of $e, or null when this request does not get one — in which case the
     * framework's own error response stands (JSON clients, the admin panel, the Modern surface).
     */
    public static function render(Request $request, HttpExceptionInterface $e): ?Response
    {
        $status = $e->getStatusCode();

        // Only requests routed through the web group get the shell: system routes (the storage
        // file server, vendor endpoints) never ran the session/locale/header middleware and must
        // keep their native errors. Checked before the surface so they don't query settings either.
        $route = $request->route();
        $isWebRoute = $route instanceof Route
            && in_array('web', $route->gatherMiddleware(), true);

        if (! $isWebRoute
            || ! in_array($status, self::STATUSES, true)
            || $request->expectsJson()
            || AdminRealm::matches($request)
            || SurfaceResolver::forError($request) !== SurfaceResolver::CLASSIC) {
            return null;
        }

        // The exception's own status and headers carry through: a 419 stays a 419, and headers an
        // abort() attached (Allow, Retry-After) are part of the error, not of the rendering.
        return response()->view('errors.classic', ['status' => $status], $status, $e->getHeaders());
    }

    /**
     * A concrete response for $e: {@see render}, else whatever the framework would have rendered.
     * For a caller that produces the error itself rather than throwing it — `Route::fallback()`,
     * which exists so that an unmatched URL is an ordinary routed request (session, locale,
     * response headers) instead of a router-level 404 that has none of them.
     */
    public static function respond(Request $request, HttpExceptionInterface $e): Response
    {
        return self::render($request, $e) ?? app(ExceptionHandler::class)->render($request, $e);
    }
}
