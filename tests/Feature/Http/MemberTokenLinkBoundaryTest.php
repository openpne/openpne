<?php

namespace Tests\Feature\Http;

use App\Features\Member\EmailChangeLinkController;
use App\Features\Member\MemberConfigController;
use App\Features\Member\MfaResetLinkController;
use App\Http\Middleware\NoReferrer;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the per-controller auth boundary of the member token-link landings. Every MemberConfigController
 * route is authenticated-only (it sits in the member `auth` group); the token-gated mail-link landings
 * (email-change confirm/cancel and the admin-issued MFA reset) are deliberately guest-reachable and live
 * on their own controllers with no auth middleware — only their throttles, NoReferrer where the URL
 * carries a secret + password, and the issued-token length constraint. A route edit that moves an authed
 * member-config action onto a guest controller, drops the member auth guard, or unpins the token
 * shape/middleware must fail here.
 */
class MemberTokenLinkBoundaryTest extends TestCase
{
    public function test_every_member_config_route_requires_member_auth(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $route) => $route->getControllerClass() === MemberConfigController::class);

        // Fail loudly if enumeration finds nothing: an empty set would let the per-route auth
        // assertion below pass vacuously, masking a broken filter or unwired controller.
        $this->assertNotEmpty($routes, 'No routes resolve to MemberConfigController — the auth-boundary sweep would pass vacuously.');

        foreach ($routes as $route) {
            // Pin the full auth-group contract, not just the guard: auth.session keeps a
            // password-change logout effective on these routes.
            foreach (['auth', 'auth.session'] as $required) {
                $this->assertContains($required, $route->gatherMiddleware(),
                    "MemberConfigController is an authenticated-only boundary, but [{$route->getName()}] carries no [{$required}] middleware.");
            }
        }
    }

    /**
     * Guest-reachable, token-gated mail-link landings.
     *
     * @return array<string, array{string, class-string, string, string, list<string>}>
     *                                                                                  name, controller, action, http method, must-have middleware
     */
    public static function tokenLinkRoutes(): array
    {
        return [
            // Email-change: per-IP throttle + token regex. These carry no NoReferrer today — a known
            // oversight (the URL holds a token); tracked for a separate fix, so it is not asserted here.
            'email.confirm' => ['member.config.email.confirm', EmailChangeLinkController::class, 'confirmEmailForm', 'GET', ['throttle:30,1']],
            'email.confirm.submit' => ['member.config.email.confirm.submit', EmailChangeLinkController::class, 'confirmEmail', 'POST', ['throttle:30,1']],
            'email.cancel' => ['member.config.email.cancel', EmailChangeLinkController::class, 'cancelEmailForm', 'GET', ['throttle:30,1']],
            'email.cancel.submit' => ['member.config.email.cancel.submit', EmailChangeLinkController::class, 'cancelEmail', 'POST', ['throttle:30,1']],

            // MFA reset: NoReferrer on both (URL secret + password); the POST also carries the per-token limiter.
            'mfa.reset' => ['member.mfa.reset', MfaResetLinkController::class, 'form', 'GET', [NoReferrer::class, 'throttle:30,1']],
            'mfa.reset.submit' => ['member.mfa.reset.submit', MfaResetLinkController::class, 'reset', 'POST', [NoReferrer::class, 'throttle:30,1', 'throttle:mfa-reset']],
        ];
    }

    /**
     * @param  class-string  $controller
     * @param  list<string>  $mustHave
     */
    #[DataProvider('tokenLinkRoutes')]
    public function test_token_link_route_keeps_its_guest_reachable_contract(string $name, string $controller, string $action, string $method, array $mustHave): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");

        // The landing is on the guest-reachable controller and action, at the expected method.
        $this->assertSame($controller.'@'.$action, $route->getActionName(),
            "route [{$name}] must land on {$controller}@{$action} (the guest-reachable boundary).");
        $this->assertContains($method, $route->methods(), "route [{$name}] must be a {$method} route.");

        $middleware = $route->gatherMiddleware();

        // Deliberately reachable logged-in or out: nothing from the auth family (auth, auth:member,
        // auth.session, ...) may appear — a plain not-contains('auth') would miss a guard variant.
        $authLike = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'auth')));
        $this->assertSame([], $authLike, "route [{$name}] must stay guest-reachable (no auth-family middleware).");

        foreach ($mustHave as $required) {
            $this->assertContains($required, $middleware, "route [{$name}] lost expected middleware [{$required}].");
        }

        // Length-pinned to the issued token shape so a scanner cannot brute the token space.
        $this->assertSame('[A-Za-z0-9]{40}', $route->wheres['token'] ?? null,
            "route [{$name}] lost its [A-Za-z0-9]{40} token constraint.");
    }
}
