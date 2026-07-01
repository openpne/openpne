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
     * Render the surface (Classic Blade or Modern Inertia) that SurfaceResolver picks for $feature.
     *
     * @param  array{classic: callable(): (View|InertiaResponse), modern: callable(): (View|InertiaResponse)}  $responders
     * @param  string|null  $bodyIdRoute  Derive the Classic body id from this canonical route name
     *                                    instead of the current one (e.g. an empty search renders the
     *                                    list page id). Still parity-derived, so no literal copy.
     */
    private function respondWith(Request $request, string $feature, array $responders, ?string $bodyIdRoute = null): View|InertiaResponse
    {
        $response = $responders[SurfaceResolver::resolve($request, $feature)]();

        // Classic body id is the OpenPNE 3 page_{module}_{action} hook, derived from the route
        // parity so it stays faithful to OpenPNE 3 (the controller holds no copy). Canonicalize
        // first: a /m/* route that fell back to Classic carries the modern name, which the parity
        // keys by canonical name.
        if ($response instanceof View) {
            $name = SurfaceResolver::canonicalName($bodyIdRoute ?? $request->route()->getName());
            $response->with('pageId', RouteParityRegistry::bodyId($name));
        }

        return $response;
    }
}
