<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\NoReferrer;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fortify's own route registration is off (Fortify::ignoreRoutes()); routes/web.php re-declares
 * the subset this app uses. These pins are the drift guard: a Fortify upgrade or a route edit
 * that loses a name, a method or — worst — a throttle must fail here, not in production.
 */
class FortifyRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, string, list<string>, list<string>}> */
    public static function pinnedRoutes(): array
    {
        // name => [method, uri, must-have middleware, must-not-have middleware]
        return [
            'login form' => ['login', 'GET', 'login', ['guest:member'], []],
            'login submit' => ['login.store', 'POST', 'login', ['guest:member', 'throttle:login'], []],
            'logout' => ['logout', 'POST', 'logout', ['auth:member'], []],
            'reset request form' => ['password.request', 'GET', 'forgot-password', ['guest:member'], []],
            'reset link mail' => ['password.email', 'POST', 'forgot-password', ['guest:member'], []],
            'reset form' => ['password.reset', 'GET', 'reset-password/{token}', ['guest:member'], []],
            'reset submit' => ['password.update', 'POST', 'reset-password', ['guest:member'], []],
            // The challenge GET is deliberately unthrottled: a refresh must not spend the guess budget.
            'challenge form' => ['two-factor.login', 'GET', 'two-factor-challenge', ['guest:member'], ['throttle:two-factor']],
            'challenge submit' => ['two-factor.login.store', 'POST', 'two-factor-challenge', ['guest:member', 'throttle:two-factor'], []],
            // The options GET is unthrottled for the same reason as the challenge form.
            'passkey options' => ['passkey.login-options', 'GET', 'passkeys/login/options', ['guest:member'], ['throttle:passkey-login']],
            'passkey login' => ['passkey.login', 'POST', 'passkeys/login', ['guest:member', 'throttle:passkey-login'], []],
        ];
    }

    /**
     * @param  list<string>  $mustHave
     * @param  list<string>  $mustNotHave
     */
    #[DataProvider('pinnedRoutes')]
    public function test_the_manually_registered_fortify_routes_keep_their_vendor_shape(
        string $name, string $method, string $uri, array $mustHave, array $mustNotHave,
    ): void {
        $route = Route::getRoutes()->getByName($name);
        $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");

        $this->assertContains($method, $route->methods());
        $this->assertSame($uri, $route->uri());

        $middleware = $route->gatherMiddleware();
        foreach ([NoReferrer::class, 'throttle:password-reset', ...$mustHave] as $required) {
            $this->assertContains($required, $middleware, "route [{$name}] lost [{$required}]");
        }
        foreach ($mustNotHave as $forbidden) {
            $this->assertNotContains($forbidden, $middleware, "route [{$name}] gained [{$forbidden}]");
        }
    }

    public function test_fortifys_two_factor_management_endpoints_are_not_registered(): void
    {
        // These bypass the app's management contract (inline current_password re-auth + session
        // revocation on factor change), so enabling the feature must not ship them.
        foreach (['two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.qr-code',
            'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes'] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), "route [{$name}] must not exist");
        }

        // Asserted against the route table rather than a response status, because Route::fallback()
        // answers any unrouted GET and a 404 would not distinguish unregistered from registered-and-404.
        foreach ([
            ['POST', '/user/two-factor-authentication'],
            ['DELETE', '/user/two-factor-authentication'],
            ['POST', '/user/confirmed-two-factor-authentication'],
            ['GET', '/user/two-factor-qr-code'],
            ['GET', '/user/two-factor-secret-key'],
            ['GET', '/user/two-factor-recovery-codes'],
        ] as [$method, $path]) {
            $request = Request::create($path, $method);
            $matched = collect(Route::getRoutes()->getRoutesByMethod()[$method] ?? [])
                ->reject(fn (RoutingRoute $route): bool => $route->isFallback)
                ->first(fn (RoutingRoute $route): bool => $route->matches($request));

            $this->assertNull($matched, "[{$method} {$path}] must not be routed");
        }
    }

    public function test_the_two_factor_management_posts_are_throttled_but_the_render_is_not(): void
    {
        // Separate from pinnedRoutes(), whose rows assume the pre-login shape; the GET render is asserted
        // unthrottled because a refresh must not spend the budget.
        foreach ([
            'member.config.mfa.enable',
            'member.config.mfa.confirm',
            'member.config.mfa.disable',
            'member.config.mfa.recovery',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");
            $this->assertContains('throttle:mfa-manage', $route->gatherMiddleware(), "route [{$name}] lost throttle:mfa-manage");
        }

        $edit = Route::getRoutes()->getByName('member.config.mfa.edit');
        $this->assertInstanceOf(RoutingRoute::class, $edit, 'route [member.config.mfa.edit] is not registered');
        $this->assertNotContains('throttle:mfa-manage', $edit->gatherMiddleware(), 'the GET render must not be throttled');
    }

    public function test_the_passkey_mutations_are_throttled_but_the_render_and_challenge_are_not(): void
    {
        foreach ([
            'member.config.passkeys.reauth',
            'member.config.passkeys.store',
            'member.config.passkeys.destroy',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");
            $this->assertContains('throttle:passkey-manage', $route->gatherMiddleware(), "route [{$name}] lost throttle:passkey-manage");
        }

        // The challenge GET is exempt too: a cancelled browser prompt refetches it.
        foreach (['member.config.passkeys.edit', 'member.config.passkeys.options'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");
            $this->assertNotContains('throttle:passkey-manage', $route->gatherMiddleware(), "route [{$name}] must not be throttled");
        }
    }

    public function test_the_packages_passkey_management_endpoints_are_not_registered(): void
    {
        // The login pair is deliberately absent from this list.
        foreach (['passkey.registration-options', 'passkey.store', 'passkey.destroy', 'passkey.confirm', 'passkey.confirm-options'] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), "route [{$name}] must not exist");
        }

        foreach ([
            ['GET', '/user/passkeys/options'],
            ['POST', '/user/passkeys'],
            ['DELETE', '/user/passkeys/1'],
            ['GET', '/passkeys/confirm/options'],
            ['POST', '/passkeys/confirm'],
        ] as [$method, $path]) {
            $request = Request::create($path, $method);
            $matched = collect(Route::getRoutes()->getRoutesByMethod()[$method] ?? [])
                ->reject(fn (RoutingRoute $route): bool => $route->isFallback)
                ->first(fn (RoutingRoute $route): bool => $route->matches($request));

            $this->assertNull($matched, "[{$method} {$path}] must not be routed");
        }
    }

    public function test_fortifys_unused_password_confirmation_routes_are_not_carried_over(): void
    {
        foreach (['password.confirm', 'password.confirm.store', 'password.confirmation'] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), "route [{$name}] must not exist");
        }
    }

    public function test_repeated_failed_logins_hit_the_login_rate_limit(): void
    {
        // Behavioral pin for throttle:login on the POST — the middleware list alone could lie
        // if the limiter definition changed shape.
        $member = Member::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => $member->email, 'password' => 'wrong-password'])
                ->assertRedirect();
        }

        $this->post('/login', ['email' => $member->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
    }
}
