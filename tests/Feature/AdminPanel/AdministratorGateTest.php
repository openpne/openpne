<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\SetLocale;
use App\Models\Member;
use App\Support\AdminRealm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Over HTTP, because Livewire::test() renders a page without the panel's middleware. */
class AdministratorGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function adminRequests(): array
    {
        return [
            'the dashboard' => ['GET', '/admin'],
            'a resource' => ['GET', '/admin/members'],
            'the panel language' => ['POST', '/admin/locale/session'],
            'the site icon' => ['POST', '/admin/site-icon/raster'],
        ];
    }

    #[DataProvider('adminRequests')]
    public function test_a_guest_is_sent_to_the_member_login_and_back(string $method, string $uri): void
    {
        $response = $this->call($method, $uri);

        $response->assertRedirect(route('login'));
        if ($method === 'GET') {
            $this->assertSame(url($uri), session('url.intended'));
        }
    }

    #[DataProvider('adminRequests')]
    public function test_a_member_without_the_role_is_refused(string $method, string $uri): void
    {
        $this->actingAs(Member::factory()->create(), 'member')->call($method, $uri)->assertForbidden();
    }

    public function test_an_administrator_whose_login_is_rejected_or_an_ai_account_is_refused(): void
    {
        $rejected = Member::factory()->administrator()->create(['is_login_rejected' => true]);
        $this->actingAs($rejected, 'member')->get('/admin')->assertForbidden();

        $ai = Member::factory()->aiAccount(Member::factory()->create())->create();
        $ai->forceFill(['admin_granted_at' => now(), 'admin_generation' => 1])->save();
        $this->actingAs($ai, 'member')->get('/admin')->assertForbidden();
    }

    public function test_an_administrator_opens_the_panel_pages(): void
    {
        $this->actingAsAdmin()->get('/admin')->assertOk();
        $this->actingAsAdmin()->get('/admin/members')->assertOk();
    }

    public function test_the_livewire_endpoints_refuse_a_member_without_the_role(): void
    {
        $this->actingAs(Member::factory()->create(), 'member')
            ->post(EndpointResolver::updatePath(), ['components' => []], ['X-Livewire' => '1'])
            ->assertForbidden();
    }

    public function test_an_inertia_visit_to_the_panel_is_told_to_load_it_in_full(): void
    {
        $this->actingAsAdmin()
            ->get('/admin/members', ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/admin/members'));
    }

    public function test_an_inertia_visit_from_a_member_without_the_role_loads_the_refusal_in_full(): void
    {
        $this->actingAs(Member::factory()->create(), 'member')
            ->get('/admin', ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/admin'));
    }

    public function test_logging_out_of_the_panel_lands_on_the_member_login(): void
    {
        $this->actingAsAdmin()->post('/admin/logout')->assertRedirect(route('login'));

        $this->assertGuest('member');
    }

    public function test_the_admin_realm_follows_the_panel_language_not_the_member_one(): void
    {
        Router::middleware('web')->get('/'.ltrim(EndpointResolver::prefix(), '/').'/locale-probe', fn () => app()->getLocale());
        Router::middleware('web')->get('/locale-probe', fn () => app()->getLocale());
        // No stored choice, so the member realm falls to the session key the panel's must not share.
        $admin = Member::factory()->administrator()->create(['locale' => null]);

        $this->actingAsAdmin($admin)->withSession([SetLocale::ADMIN_SESSION_KEY => 'en', 'locale' => 'ja']);

        $this->get('/'.ltrim(EndpointResolver::prefix(), '/').'/locale-probe')->assertOk()->assertContent('en');
        $this->get('/locale-probe')->assertOk()->assertContent('ja');
    }

    public function test_every_admin_realm_route_runs_the_gate_before_its_bindings(): void
    {
        $router = $this->app['router'];
        $checked = 0;
        $ungated = [];

        foreach ($router->getRoutes() as $route) {
            if (! AdminRealm::matches(Request::create('/'.ltrim((string) preg_replace('/\{[^}]+\}/', 'x', $route->uri()), '/')))
                || in_array($route->getName(), EnsureAdministrator::EXEMPT, true)
                || self::isLivewireAsset($route)) {
                continue;
            }
            $checked++;

            $middleware = $router->gatherRouteMiddleware($route);
            $gate = array_search(EnsureAdministrator::class, $middleware, true);
            $bindings = array_search(SubstituteBindings::class, $middleware, true);
            if ($gate === false || ($bindings !== false && $bindings < $gate)) {
                $ungated[] = $route->uri();
            }
        }

        $this->assertGreaterThan(40, $checked, 'the admin realm predicate matched too few routes to mean anything');
        $this->assertSame([], $ungated);
    }

    /** Livewire's own script and its components' styles carry no middleware at all, so no session to read. */
    private static function isLivewireAsset(Route $route): bool
    {
        return $route->gatherMiddleware() === []
            && in_array($route->methods(), [['GET', 'HEAD']], true)
            && str_starts_with($route->uri(), ltrim(EndpointResolver::prefix(), '/').'/')
            && preg_match('#/(livewire[.\w]*\.js(\.map)?|(css|js)/\{component\}[.\w]*)$#', $route->uri()) === 1;
    }
}
