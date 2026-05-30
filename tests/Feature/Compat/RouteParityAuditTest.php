<?php

namespace Tests\Feature\Compat;

use App\Compat\Openpne3Routes;
use App\Compat\RouteParityRegistry;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Binds the route parities to reality: declared Laravel routes must exist, every OpenPNE 3
 * route must be mapped or gapped, and the declared OpenPNE 3 URLs must match the inventory.
 */
class RouteParityAuditTest extends TestCase
{
    public function test_declared_laravel_routes_exist(): void
    {
        foreach (RouteParityRegistry::all() as $parity) {
            foreach ($parity->maps() as $map) {
                $this->assertNotNull(Route::getRoutes()->getByName($map->laravelRoute),
                    "{$parity->module()}: route `{$map->laravelRoute}` is declared but not registered");
            }
        }
    }

    public function test_declared_methods_match_the_live_routes(): void
    {
        foreach (RouteParityRegistry::all() as $parity) {
            foreach ($parity->maps() as $map) {
                $route = Route::getRoutes()->getByName($map->laravelRoute);

                $this->assertContains($map->method, $route->methods(),
                    "{$parity->module()}: `{$map->laravelRoute}` is declared {$map->method} but serves "
                    .implode('|', $route->methods()));
            }
        }
    }

    public function test_every_openpne3_route_is_mapped_or_gapped(): void
    {
        $inventory = Openpne3Routes::default();

        foreach (RouteParityRegistry::all() as $parity) {
            $accounted = array_merge($parity->mappedRoutes(), array_keys($parity->gaps()));

            foreach ($inventory->routeNames($parity->module()) as $route) {
                $this->assertContains($route, $accounted,
                    "{$parity->module()}: route `{$route}` is neither mapped nor gapped (un-ported endpoint)");
            }
        }
    }

    public function test_url_compatible_routes_stay_get_reachable(): void
    {
        // A GET-reachable OpenPNE 3 URL (bookmarked / mailed / linked) keeps its
        // URL-preservation obligation only if the Laravel route it maps to also answers GET.
        $inventory = Openpne3Routes::default();

        foreach (RouteParityRegistry::all() as $parity) {
            foreach ($parity->maps() as $map) {
                if (! $inventory->isUrlCompatible($parity->module(), $map->op3Route)) {
                    continue;
                }

                $route = Route::getRoutes()->getByName($map->laravelRoute);
                $this->assertContains('GET', $route->methods(),
                    "{$parity->module()}: `{$map->op3Route}` is URL-compatible but `{$map->laravelRoute}` does not serve GET");
            }
        }
    }

    public function test_named_route_coverage_is_exhaustive(): void
    {
        // The coverage audit above iterates named routes only. That is exhaustive solely
        // for modules that disable OpenPNE 3's global /:module/:action/* fallback; otherwise
        // un-named actions stay reachable and would slip past coverage. Flag any parity whose
        // module leaves the fallback on so its completeness is handled consciously.
        $inventory = Openpne3Routes::default();

        foreach (RouteParityRegistry::all() as $parity) {
            $this->assertTrue($inventory->disablesGlobalFallback($parity->module()),
                "{$parity->module()}: global fallback is on, so named-route coverage is not exhaustive");
        }
    }

    public function test_mapped_openpne3_urls_match_the_inventory(): void
    {
        $inventory = Openpne3Routes::default();

        foreach (RouteParityRegistry::all() as $parity) {
            foreach ($parity->maps() as $map) {
                $this->assertSame($inventory->url($parity->module(), $map->op3Route), $map->op3Url,
                    "{$parity->module()}: route `{$map->op3Route}` declares {$map->op3Url} but the inventory differs");
            }
        }
    }
}
