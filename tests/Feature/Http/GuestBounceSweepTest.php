<?php

namespace Tests\Feature\Http;

use App\Support\AdminRealm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every member-realm route is requested as a guest and must end at the login screen unless its name
 * is listed here or in PublicRouteBoundaryTest, so a route added without `auth` fails the day it
 * lands. Compat redirects are followed, so they are judged by where they end.
 */
class GuestBounceSweepTest extends TestCase
{
    use RefreshDatabase;

    /** First URI segments of the token realm, the framework's own endpoints and dev-only tooling; the admin realm is AdminRealm's. */
    private const OUTSIDE_SEGMENTS = ['mcp', 'storage', 'up', '_boost', '_inertia'];

    /** The sign-in flow and the token links a member opens while signed out. */
    private const GUEST_FLOW = [
        'login', 'login.store',
        'password.request', 'password.email', 'password.reset', 'password.update',
        'two-factor.login', 'two-factor.login.store',
        'passkey.login', 'passkey.login-options',
        'register', 'register.request', 'register.sent', 'register.form', 'register.complete',
        'member.config.email.confirm', 'member.config.email.confirm.submit',
        'member.config.email.cancel', 'member.config.email.cancel.submit',
        'member.mfa.reset', 'member.mfa.reset.submit',
    ];

    /** Site assets and pages that render without a viewer. */
    private const SITE_PUBLIC = [
        'policy.terms', 'policy.privacy', 'webmanifest', 'altcha.challenge', 'app_icon',
        'design.customizing_css', 'banner.image', 'file.public', 'locale.switch',
    ];

    /** Tried in order against each parameter's `where` pattern; the first match stands in for it. */
    private const PLACEHOLDERS = ['1', '2026', 'x', '00000000-0000-4000-8000-000000000000'];

    public function test_every_other_route_sends_a_guest_to_login(): void
    {
        $failures = [];
        $swept = [];

        foreach (Route::getRoutes() as $route) {
            if ($this->isOutsideTheSweep($route)) {
                continue;
            }

            $swept[] = $route->getName();
            $method = $this->methodOf($route);
            $landing = $this->whereAGuestLands($method, $this->urlOf($route));
            if ($landing !== null) {
                $failures[] = sprintf('%s /%s [%s] -> %s', $method, $route->uri(), $route->getName() ?? '-', $landing);
            }
        }

        $this->assertSame([], $failures, "A guest did not end at /login on:\n".implode("\n", $failures)
            ."\nGive the route `auth`, or list it in GUEST_FLOW / SITE_PUBLIC here or in PublicRouteBoundaryTest::publicMemberRoutes() if a guest may open it.");
        // A page, a POST, a DELETE and a compat redirect: the sweep reached each kind.
        foreach (['dashboard', 'group.talk.store', 'member.config.passkeys.destroy', 'group.show.compat'] as $sentinel) {
            $this->assertContains($sentinel, $swept);
        }
    }

    public function test_the_guest_lists_name_registered_routes(): void
    {
        foreach ([...self::GUEST_FLOW, ...self::SITE_PUBLIC] as $name) {
            $this->assertTrue(Route::has($name), "Listed route [{$name}] no longer exists; remove it.");
        }
    }

    /** @return list<string> */
    private static function guestRoutes(): array
    {
        return [...array_column(PublicRouteBoundaryTest::publicMemberRoutes(), 0), ...self::GUEST_FLOW, ...self::SITE_PUBLIC];
    }

    private function isOutsideTheSweep(RoutingRoute $route): bool
    {
        if ($route->isFallback) {
            return true;
        }
        if (in_array(explode('/', $route->uri())[0], self::OUTSIDE_SEGMENTS, true)) {
            return true;
        }
        if (AdminRealm::matches(Request::create('/'.ltrim(preg_replace('/\{[^}]+\}/', 'x', $route->uri()), '/')))) {
            return true;
        }

        return in_array($route->getName(), self::guestRoutes(), true);
    }

    private function methodOf(RoutingRoute $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

        return in_array('GET', $methods, true) ? 'GET' : $methods[0];
    }

    /** Optional parameters are left out, so a compat shim is asked for its shortest form. */
    private function urlOf(RoutingRoute $route): string
    {
        $uri = preg_replace('#/?\{\w+(?::\w+)?\?\}#', '', $route->uri());
        $uri = preg_replace_callback('/\{(\w+)(?::\w+)?\}/', function (array $match) use ($route): string {
            $pattern = $route->wheres[$match[1]] ?? null;
            foreach (self::PLACEHOLDERS as $candidate) {
                if ($pattern === null || preg_match('#^(?:'.$pattern.')$#', $candidate) === 1) {
                    return $candidate;
                }
            }
            $this->fail("No entry of PLACEHOLDERS matches {{$match[1]}} ({$pattern}) on /{$route->uri()}; add one.");
        }, $uri);

        return '/'.ltrim($uri, '/');
    }

    /** Returns null once the guest reaches /login or a listed guest route, else the path taken and where it ended. */
    private function whereAGuestLands(string $method, string $url): ?string
    {
        $trail = [];
        for ($hop = 0; $hop < 5; $hop++) {
            $trail[] = "{$method} {$url}";
            $response = $this->call($method, $url);
            if (! $response->isRedirect()) {
                $name = $response->baseRequest->route()?->getName();
                if ($name !== null && in_array($name, self::guestRoutes(), true)) {
                    return null;
                }

                return implode(' -> ', $trail).' -> HTTP '.$response->getStatusCode().($name === null ? '' : " at [{$name}]");
            }

            $target = (string) $response->headers->get('Location');
            $host = parse_url($target, PHP_URL_HOST);
            if ($host !== null && $host !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
                return implode(' -> ', $trail)." -> off-site {$target}";
            }
            $path = parse_url($target, PHP_URL_PATH) ?: '/';
            if ($path === '/login') {
                return null;
            }
            $query = parse_url($target, PHP_URL_QUERY);
            $method = 'GET';
            $url = $path.(is_string($query) ? "?{$query}" : '');
        }

        return implode(' -> ', $trail).' -> still redirecting';
    }
}
