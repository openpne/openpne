<?php

namespace Tests\Feature\Compat;

use App\Support\SurfaceResolver;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every Modern (surface=modern) route must be named `{feature}.modern.{rest}` so that
 * SurfaceResolver::redirectName()/canonicalName() round-trip: a post-submit redirect built from
 * the canonical name on a Modern request must resolve back to the live Modern route. A name whose
 * `.modern.` infix does not sit right after the feature segment (e.g. `member.profile.modern.edit`)
 * makes redirectName() build a nonexistent target and the submit 500s on the Modern surface.
 */
class ModernRouteConventionTest extends TestCase
{
    public function test_modern_routes_round_trip_through_redirect_name(): void
    {
        $modernRoutes = $this->modernRouteNames();
        $this->assertNotEmpty($modernRoutes, 'expected at least one surface=modern route to exist');

        foreach ($modernRoutes as $name) {
            $canonical = SurfaceResolver::canonicalName($name);
            $this->assertNotSame($canonical, $name,
                "Modern route `{$name}` has no `.modern.` infix, so canonicalName() cannot derive its canonical twin");

            // Mirror redirectName()'s transform: the `.modern.` infix sits right after the feature
            // (first) segment. redirectName($canonical) must reproduce this exact route name.
            $feature = strstr($canonical, '.', true);
            $expected = $feature.'.modern.'.substr($canonical, strlen($feature) + 1);
            $this->assertSame($expected, $name,
                "Modern route `{$name}` is off-convention: redirectName() would build `{$expected}` from `{$canonical}` and 500");

            $this->assertNotNull(Route::getRoutes()->getByName($canonical),
                "Modern route `{$name}` has no canonical twin `{$canonical}` to redirect from");
        }
    }

    /** @return list<string> */
    private function modernRouteNames(): array
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            if (($route->defaults['surface'] ?? null) === SurfaceResolver::MODERN && $route->getName() !== null) {
                $names[] = $route->getName();
            }
        }

        return $names;
    }
}
