<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Support\Feature;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route a feature unit owns must carry that unit's gate. Walks the WHOLE route inventory
 * rather than a route-name scan: ownership is claimed by name prefix, by URL segment (which catches
 * an unnamed alias), and by an explicit map for the endpoints that sit outside their unit's prefix.
 * A route added later to a gated feature therefore fails here until it is gated or allowlisted.
 * Ownership is single, but the gate set need not be: a route may also depend on another unit
 * (DEPENDENCIES), and then every gate in the set is required.
 */
class FeatureRouteMiddlewarePinTest extends TestCase
{
    /** Feature-owned routes whose name and URL both sit outside the owning unit's prefix. */
    private const OUT_OF_PREFIX = [
        'member.config.diary' => 'diary',
        'notifications.center.friendAccept' => 'friend',
        'notifications.center.friendReject' => 'friend',
    ];

    /**
     * Routes needing a second unit's gate as well as their owner's: a screen one unit owns whose
     * purpose is another unit's lens (the friend diary feed). Both gates are required here and
     * neither counts as a stray below, so the pair cannot decay to one.
     *
     * @var array<string, list<string>>
     */
    private const DEPENDENCIES = [
        'diary.list_friend' => ['friend'],
        // A community's timeline: community screens whose purpose is the timeline, so switching
        // either unit off has to take them away.
        'community.timeline' => ['timeline'],
        'community.timeline.new' => ['timeline'],
        'community.timeline.store' => ['timeline'],
        'community.timeline.compat' => ['timeline'],
    ];

    /**
     * Feature-owned routes deliberately left ungated. Empty: the retired `/m/*` redirects are the
     * only feature-shaped URLs without a gate, and they are claimed by neither rule (their canonical
     * target answers). The const stays as the tripwire — a new exemption is a conscious entry here.
     *
     * @var list<string>
     */
    private const UNGATED = [];

    public function test_every_feature_owned_route_carries_its_gate(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $feature = $this->owner($route);
            $name = (string) $route->getName();

            if ($feature === null || in_array($name, self::UNGATED, true)) {
                continue;
            }

            foreach ([$feature->value, ...(self::DEPENDENCIES[$name] ?? [])] as $required) {
                if (! in_array(EnsureFeatureEnabled::class.':'.$required, $route->gatherMiddleware(), true)) {
                    $offenders[] = ($name === '' ? '(unnamed)' : $name)." [{$route->uri()}] expects {$required}";
                }
            }
        }

        $this->assertSame([], $offenders,
            'Feature-owned routes without their EnsureFeatureEnabled gate: '.implode(', ', $offenders));
    }

    public function test_no_route_carries_a_gate_for_a_unit_that_does_not_own_it(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, EnsureFeatureEnabled::class.':')) {
                    continue;
                }

                $declared = substr($middleware, strlen(EnsureFeatureEnabled::class) + 1);
                $allowed = in_array($declared, self::DEPENDENCIES[(string) $route->getName()] ?? [], true);
                if (! $allowed && Feature::tryFrom($declared) !== $this->owner($route)) {
                    $offenders[] = $route->getName().' [gated as '.$declared.']';
                }
            }
        }

        $this->assertSame([], $offenders,
            'Routes gated as a unit that does not own them (typo, or the ownership map needs the route): '.implode(', ', $offenders));
    }

    /**
     * The gate's slot in the resolved stack (bootstrap/app.php priority list): after auth, so a
     * guest in an auth group meets the login redirect and the toggle state never shows; before
     * ThrottleRequests, so a disabled unit's request consumes no limiter; before
     * SubstituteBindings, so a binding's missing() handler (the /diary/listMember guest bounce)
     * cannot outrank the 404.
     */
    public function test_the_gate_runs_after_auth_and_before_throttle_and_bindings(): void
    {
        $router = app(Router::class);
        $gated = 0;

        foreach (Route::getRoutes() as $route) {
            $sorted = $router->gatherRouteMiddleware($route);
            $at = function (string $class) use ($sorted): int|false {
                foreach ($sorted as $i => $middleware) {
                    if (is_string($middleware) && str_starts_with($middleware, $class)) {
                        return $i;
                    }
                }

                return false;
            };

            $gateAt = $at(EnsureFeatureEnabled::class);
            if ($gateAt === false) {
                continue;
            }
            $gated++;

            foreach ([ThrottleRequests::class, SubstituteBindings::class] as $mustFollow) {
                $followerAt = $at($mustFollow);
                if ($followerAt !== false) {
                    $this->assertLessThan($followerAt, $gateAt,
                        ($route->getName() ?: $route->uri()).": the gate must precede {$mustFollow}");
                }
            }

            $authAt = $at(Authenticate::class);
            if ($authAt !== false) {
                $this->assertLessThan($gateAt, $authAt,
                    ($route->getName() ?: $route->uri()).': auth must precede the gate');
            }
        }

        $this->assertGreaterThan(0, $gated);
    }

    public function test_the_route_maps_name_real_routes(): void
    {
        foreach ([...array_keys(self::OUT_OF_PREFIX), ...array_keys(self::DEPENDENCIES)] as $name) {
            $this->assertTrue(Route::has($name), "Mapped route [{$name}] no longer exists — remove it or fix the name.");
        }
        foreach (self::UNGATED as $name) {
            $this->assertTrue(Route::has($name), "Allowlisted route [{$name}] no longer exists — remove it or fix the name.");
        }
    }

    /** The unit a route belongs to: its name prefix, its URL's first segment, or the explicit map. */
    private function owner(RoutingRoute $route): ?Feature
    {
        $name = (string) $route->getName();

        return Feature::owningRouteName($name)
            ?? (isset(self::OUT_OF_PREFIX[$name]) ? Feature::from(self::OUT_OF_PREFIX[$name]) : null)
            ?? Feature::tryFrom(explode('/', $route->uri())[0]);
    }
}
