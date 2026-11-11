<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\SessionGuard;
use App\Models\Member;
use Illuminate\Auth\SessionGuard as FrameworkSessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use Tests\TestCase;

class CookiePrefixTest extends TestCase
{
    use RefreshDatabase;

    private function frameworkRecaller(string $guard = 'member'): string
    {
        return 'remember_'.$guard.'_'.sha1(FrameworkSessionGuard::class);
    }

    private function rememberedLogin(Member $member): TestResponse
    {
        return $this->post('/login', ['email' => $member->email, 'password' => 'password', 'remember' => '1'])->assertRedirect();
    }

    public function test_a_plain_http_site_keeps_the_framework_names(): void
    {
        $this->assertSame($this->frameworkRecaller(), Auth::guard('member')->getRecallerName());

        $response = $this->get('/login');
        $response->assertCookie('XSRF-TOKEN');
    }

    public function test_no_provider_builds_the_cookie_jar_while_booting(): void
    {
        // The jar copies session.secure when it is built, and force_https only sets that during
        // boot: a jar built earlier would issue the prefixed recaller without the Secure flag.
        $this->refreshApplication();

        $this->assertFalse($this->app->resolved('cookie'));
    }

    public function test_a_secure_host_only_site_issues_both_cookies_under_the_host_prefix(): void
    {
        config(['session.driver' => 'database', 'session.secure' => true]);
        $recaller = '__Host-'.$this->frameworkRecaller();

        $response = $this->rememberedLogin(Member::factory()->create());

        $this->assertSame($recaller, Auth::guard('member')->getRecallerName());
        $response->assertCookieMissing($this->frameworkRecaller())->assertCookieMissing('XSRF-TOKEN');
        foreach ([$recaller, '__Host-XSRF-TOKEN'] as $name) {
            $cookie = $response->getCookie($name, false);
            $this->assertNotNull($cookie, $name);
            // The terms a browser holds a __Host- cookie to; one unmet and it drops the cookie.
            $this->assertTrue($cookie->isSecure(), $name);
            $this->assertNull($cookie->getDomain(), $name);
            $this->assertSame('/', $cookie->getPath(), $name);
        }
    }

    public function test_a_cookie_scoped_to_a_parent_domain_falls_back_to_the_secure_prefix(): void
    {
        config(['session.driver' => 'database', 'session.secure' => true, 'session.domain' => '.example.test']);

        $response = $this->rememberedLogin(Member::factory()->create());

        foreach (['__Secure-'.$this->frameworkRecaller(), '__Secure-XSRF-TOKEN'] as $name) {
            $cookie = $response->getCookie($name, false);
            $this->assertNotNull($cookie, $name);
            $this->assertTrue($cookie->isSecure(), $name);
        }
    }

    public function test_only_the_prefixed_recaller_restores_a_login(): void
    {
        config(['session.driver' => 'database', 'session.secure' => true]);
        $member = Member::factory()->create();
        $prefixed = '__Host-'.$this->frameworkRecaller();
        $value = $this->rememberedLogin($member)->getCookie($prefixed)->getValue();

        // The name another host under the same parent domain can still plant; withCookie()
        // encrypts under the name it is given, so only the name differs from the real cookie.
        $this->freshRequestState();
        $this->withCookie($this->frameworkRecaller(), $value)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest('member');

        $this->freshRequestState();
        $this->withCookie($prefixed, $value)->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($member, 'member');
    }

    public function test_the_admin_realm_strips_the_prefixed_xsrf_cookie(): void
    {
        config(['session.driver' => 'database', 'session.secure' => true]);

        $this->get('/login')->assertCookie('__Host-XSRF-TOKEN');

        $this->freshRequestState();
        $this->get('/admin/login')->assertCookieMissing('__Host-XSRF-TOKEN')->assertCookieMissing('XSRF-TOKEN');
    }

    public function test_the_modern_shell_declares_the_xsrf_cookie_name(): void
    {
        config(['session.secure' => true, 'openpne.surface_mode' => 'modern_default']);

        $this->actingAs(Member::factory()->create(), 'member')
            ->get('/member/config')
            ->assertSee('<meta name="xsrf-cookie" content="__Host-XSRF-TOKEN">', false);
    }

    public function test_both_session_guards_resolve_to_the_app_guard_during_a_request(): void
    {
        $this->get('/login');

        $this->assertInstanceOf(SessionGuard::class, Auth::guard('member'));
        $this->assertInstanceOf(SessionGuard::class, Auth::guard('admin'));
    }

    public function test_the_app_guard_is_wired_like_the_framework_guard(): void
    {
        // Non-default values, so an argument make() forgot to pass shows up as a difference.
        config(['hashing.rehash_on_login' => false, 'auth.timebox_duration' => 1]);
        $config = ['remember' => 5] + config('auth.guards.member');

        $framework = Auth::createSessionDriver('member', $config);
        $app = SessionGuard::make($this->app, 'member', $config);

        foreach ((new ReflectionClass(FrameworkSessionGuard::class))->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $describe = fn (mixed $value): mixed => is_object($value) ? $value::class : $value;

            $this->assertSame($describe($property->getValue($framework)), $describe($property->getValue($app)), $property->getName());
        }
    }

    public function test_a_session_written_by_the_framework_guard_stays_signed_in(): void
    {
        $member = Member::factory()->create();
        $key = 'login_member_'.sha1(FrameworkSessionGuard::class);

        $this->assertSame($key, Auth::guard('member')->getName());
        $this->withSession([$key => $member->getKey()])->get('/dashboard')->assertOk();
    }
}
