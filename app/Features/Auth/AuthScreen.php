<?php

namespace App\Features\Auth;

use App\Compat\RouteParityRegistry;
use App\Support\SurfaceResolver;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

class AuthScreen
{
    /**
     * Classic returns the OpenPNE 3 Blade shell with the route-parity body id and the pre-login
     * `insecure_page` class; Modern returns the Inertia page. `$bodyIdRoute` is passed explicitly so
     * the body id is keyed on the parity contract, not on the request that happens to render it.
     */
    public static function render(Request $request, string $bodyIdRoute, string $classicView, Closure $modern, array $data = []): View|InertiaResponse
    {
        if (SurfaceResolver::resolve($request, 'auth') === SurfaceResolver::CLASSIC) {
            return view($classicView, $data)
                ->with('pageId', RouteParityRegistry::bodyId($bodyIdRoute))
                ->with('pageClass', 'insecure_page');
        }

        return $modern();
    }
}
