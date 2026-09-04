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
 * Pins the auth boundary of the member token-link landings: every MemberConfigController route is
 * authenticated-only, while the mail-link landings (email-change confirm/cancel, the admin-issued MFA
 * reset) are deliberately guest-reachable on their own controllers with only their throttles,
 * NoReferrer where the URL carries a secret, and the issued-token length constraint.
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
     * @return array<string, array{string, class-string, string, string, list<string>, list<string>}>
     *                                                                                                name, controller, action, http method, must-have middleware, forbidden middleware
     */
    public static function tokenLinkRoutes(): array
    {
        return [
            // The per-token mfa-reset limiter must never leak onto the email-change landings.
            'email.confirm' => ['member.config.email.confirm', EmailChangeLinkController::class, 'confirmEmailForm', 'GET', ['throttle:30,1'], ['throttle:mfa-reset']],
            'email.confirm.submit' => ['member.config.email.confirm.submit', EmailChangeLinkController::class, 'confirmEmail', 'POST', ['throttle:30,1'], ['throttle:mfa-reset']],
            'email.cancel' => ['member.config.email.cancel', EmailChangeLinkController::class, 'cancelEmailForm', 'GET', ['throttle:30,1'], ['throttle:mfa-reset']],
            'email.cancel.submit' => ['member.config.email.cancel.submit', EmailChangeLinkController::class, 'cancelEmail', 'POST', ['throttle:30,1'], ['throttle:mfa-reset']],

            // The per-token limiter is POST-only: it guards password guesses, and a render must not
            // spend the guess budget.
            'mfa.reset' => ['member.mfa.reset', MfaResetLinkController::class, 'form', 'GET', [NoReferrer::class, 'throttle:30,1'], ['throttle:mfa-reset']],
            'mfa.reset.submit' => ['member.mfa.reset.submit', MfaResetLinkController::class, 'reset', 'POST', [NoReferrer::class, 'throttle:30,1', 'throttle:mfa-reset'], []],
        ];
    }

    /**
     * @param  class-string  $controller
     * @param  list<string>  $mustHave
     * @param  list<string>  $mustNotHave
     */
    #[DataProvider('tokenLinkRoutes')]
    public function test_token_link_route_keeps_its_guest_reachable_contract(string $name, string $controller, string $action, string $method, array $mustHave, array $mustNotHave): void
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

        foreach ($mustNotHave as $forbidden) {
            $this->assertNotContains($forbidden, $middleware, "route [{$name}] must not carry middleware [{$forbidden}].");
        }

        // Length-pinned to the issued token shape so a scanner cannot brute the token space.
        $this->assertSame('[A-Za-z0-9]{40}', $route->wheres['token'] ?? null,
            "route [{$name}] lost its [A-Za-z0-9]{40} token constraint.");
    }
}
