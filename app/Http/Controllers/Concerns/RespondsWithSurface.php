<?php

namespace App\Http\Controllers\Concerns;

use App\Compat\RouteParityRegistry;
use App\Support\SurfaceResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Response as InertiaResponse;

trait RespondsWithSurface
{
    /**
     * @param  array{classic: callable(): (View|InertiaResponse), modern: callable(): (View|InertiaResponse)}  $responders
     * @param  string|null  $bodyIdRoute  canonical route name whose Classic body id is used instead of
     *                                    the current route's (an empty search renders the list page id)
     */
    private function respondWith(Request $request, string $feature, array $responders, ?string $bodyIdRoute = null): View|InertiaResponse
    {
        $response = $responders[SurfaceResolver::resolve($request, $feature)]();

        // Classic body id is the OpenPNE 3 page_{module}_{action} hook, derived from the route
        // parity so it stays faithful to OpenPNE 3 (the controller holds no copy).
        if ($response instanceof View) {
            $response->with('pageId', RouteParityRegistry::bodyId($bodyIdRoute ?? $request->route()->getName()));
        }

        return $response;
    }
}
