<?php

namespace Tests\Feature\Compat;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The URL space is canonical-only: the surface is an attribute of the viewer (SurfaceResolver),
 * never of the URL. Guards against the transition-era /m/ mechanics returning — a Modern URL
 * prefix, a `.modern.` route-name twin, or a `surface` route default would all reintroduce
 * URL-addressed surfaces.
 */
class RouteSpaceGuardTest extends TestCase
{
    public function test_no_route_lives_under_the_m_prefix_except_the_compat_redirects(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if ($uri !== 'm' && ! str_starts_with($uri, 'm/')) {
                continue;
            }
            // The permanent compat redirects: the prefix-strip catch-all and the retired RESTful
            // community GET shapes (Closure redirects registered beside it in routes/web.php).
            if ($route->getName() === 'compat.m_prefix' || $route->getActionName() === 'Closure') {
                continue;
            }
            $offenders[] = $uri;
        }

        $this->assertSame([], $offenders, 'Routes under /m/ (the Modern URL space is retired): '.implode(', ', $offenders));
    }

    public function test_no_route_name_carries_a_modern_infix(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName())
            ->filter(fn ($n) => $n !== null && str_contains($n, '.modern.'))->values()->all();

        $this->assertSame([], $names, 'Route names with a .modern. infix: '.implode(', ', $names));
    }

    public function test_no_route_carries_a_surface_default(): void
    {
        $offenders = collect(Route::getRoutes())
            ->filter(fn ($r) => array_key_exists('surface', $r->defaults))
            ->map(fn ($r) => $r->uri())->values()->all();

        $this->assertSame([], $offenders, 'Routes with a surface default: '.implode(', ', $offenders));
    }
}
