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

    public function test_get_routes_declare_an_op3_action_for_body_id(): void
    {
        // The Classic body id is derived from op3Action; a GET route that renders HTML must
        // declare one, or its page would silently lose its OpenPNE 3 page_{module}_{action} hook.
        foreach (RouteParityRegistry::all() as $parity) {
            foreach ($parity->maps() as $map) {
                if ($map->method !== 'GET') {
                    continue;
                }

                $this->assertNotNull($map->op3Action,
                    "{$parity->module()}: GET `{$map->laravelRoute}` has no op3Action, so it derives no body id");
            }
        }
    }

    public function test_every_openpne3_route_is_mapped_or_gapped(): void
    {
        $inventory = Openpne3Routes::default();

        foreach (RouteParityRegistry::all() as $parity) {
            $module = $parity->openpne3Module();
            if ($module === null) {
                continue; // OpenPNE 4-native feature: no inventory module to cover
            }

            $accounted = array_merge($parity->mappedRoutes(), array_keys($parity->gaps()));

            foreach ($inventory->routeNames($module) as $route) {
                $this->assertContains($route, $accounted,
                    "{$module}: route `{$route}` is neither mapped nor gapped (un-ported endpoint)");
            }
        }
    }

    public function test_url_compatible_routes_stay_get_reachable(): void
    {
        // A GET-reachable OpenPNE 3 URL (bookmarked / mailed / linked) keeps its
        // URL-preservation obligation: at least one Laravel route it maps to must answer GET.
        // Grouped per OpenPNE 3 route, because one route may split into a GET confirm + POST
        // submit (obj_friend_unlink). Maps with no named OpenPNE 3 route are out of scope.
        $inventory = Openpne3Routes::default();

        foreach (RouteParityRegistry::all() as $parity) {
            $getReachable = [];
            foreach ($parity->maps() as $map) {
                if ($map->op3Route === null
                    || ! $inventory->isUrlCompatible($parity->module(), $map->op3Route)) {
                    continue;
                }

                $servesGet = in_array('GET', Route::getRoutes()->getByName($map->laravelRoute)->methods(), true);
                $getReachable[$map->op3Route] = ($getReachable[$map->op3Route] ?? false) || $servesGet;
            }

            foreach ($getReachable as $op3Route => $hasGet) {
                $this->assertTrue($hasGet,
                    "{$parity->module()}: `{$op3Route}` is URL-compatible but no mapped Laravel route serves GET");
            }
        }
    }

    public function test_fallback_acknowledgement_matches_the_inventory(): void
    {
        // The coverage audit above iterates named routes only, so it is exhaustive solely for a
        // module that disables OpenPNE 3's global /:module/:action/* fallback. A module that
        // keeps the fallback on has un-named reachable actions, so its parity must consciously
        // acknowledge non-exhaustive coverage rather than let the gap pass silently. A native
        // feature with no OpenPNE 3 module has no fallback to acknowledge.
        $inventory = Openpne3Routes::default();

        foreach (RouteParityRegistry::all() as $parity) {
            $module = $parity->openpne3Module();
            if ($module === null) {
                $this->assertFalse($parity->acknowledgesGlobalFallback(),
                    "{$parity->module()}: has no OpenPNE 3 module, so it cannot acknowledge a global fallback");

                continue;
            }

            $this->assertSame(
                ! $inventory->disablesGlobalFallback($module),
                $parity->acknowledgesGlobalFallback(),
                "{$module}: acknowledgesGlobalFallback() must match the inventory's fallback state");
        }
    }

    public function test_mapped_openpne3_urls_match_the_inventory(): void
    {
        $inventory = Openpne3Routes::default();

        foreach (RouteParityRegistry::all() as $parity) {
            foreach ($parity->maps() as $map) {
                if ($map->op3Route === null) {
                    continue; // no named OpenPNE 3 route (fallback-reached or OpenPNE 4-native)
                }

                $this->assertSame($inventory->url($parity->module(), $map->op3Route), $map->op3Url,
                    "{$parity->module()}: route `{$map->op3Route}` declares {$map->op3Url} but the inventory differs");
            }
        }
    }
}
