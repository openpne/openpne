<?php

namespace Tests\Feature\Http;

use App\Features\Member\EmailChangeLinkController;
use App\Features\Member\MemberConfigController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the per-controller auth boundary of the member email-change split. Every MemberConfigController
 * route is authenticated-only (it sits in the member `auth` group); the token-gated mail-link landings
 * are deliberately guest-reachable and live on EmailChangeLinkController with no auth middleware — only
 * their per-IP throttle and the issued-token length constraint. A route edit that moves an authed
 * member-config action onto the guest controller, drops the member auth guard, or unpins the token
 * shape must fail here.
 */
class EmailChangeLinkBoundaryTest extends TestCase
{
    public function test_every_member_config_route_requires_member_auth(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $route) => $route->getControllerClass() === MemberConfigController::class);

        // Fail loudly if enumeration finds nothing: an empty set would let the per-route auth
        // assertion below pass vacuously, masking a broken filter or unwired controller.
        $this->assertNotEmpty($routes, 'No routes resolve to MemberConfigController — the auth-boundary sweep would pass vacuously.');

        foreach ($routes as $route) {
            $this->assertContains('auth', $route->gatherMiddleware(),
                "MemberConfigController is an authenticated-only boundary, but [{$route->getName()}] carries no member auth middleware.");
        }
    }

    /** @return array<string, array{string, string}> */
    public static function emailChangeLinkRoutes(): array
    {
        // route name => controller action (guest-reachable, token-gated mail-link landings)
        return [
            'member.config.email.confirm' => ['member.config.email.confirm', 'confirmEmailForm'],
            'member.config.email.confirm.submit' => ['member.config.email.confirm.submit', 'confirmEmail'],
            'member.config.email.cancel' => ['member.config.email.cancel', 'cancelEmailForm'],
            'member.config.email.cancel.submit' => ['member.config.email.cancel.submit', 'cancelEmail'],
        ];
    }

    #[DataProvider('emailChangeLinkRoutes')]
    public function test_email_change_link_route_keeps_its_guest_reachable_contract(string $name, string $action): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");

        // The landing is on the guest-reachable controller, not the authed member-config one.
        $this->assertSame(EmailChangeLinkController::class.'@'.$action, $route->getActionName(),
            "route [{$name}] must land on EmailChangeLinkController@{$action} (the guest-reachable boundary).");

        $middleware = $route->gatherMiddleware();

        // Deliberately reachable logged-in or out: no member auth guard, only the per-IP throttle.
        $this->assertNotContains('auth', $middleware, "route [{$name}] must stay guest-reachable (no member auth middleware).");
        $this->assertContains('throttle:30,1', $middleware, "route [{$name}] lost its per-IP throttle [throttle:30,1].");

        // Length-pinned to the issued token shape so a scanner cannot brute the token space.
        $this->assertSame('[A-Za-z0-9]{40}', $route->wheres['token'] ?? null,
            "route [{$name}] lost its [A-Za-z0-9]{40} token constraint.");
    }
}
