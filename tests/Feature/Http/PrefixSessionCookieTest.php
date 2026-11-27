<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Middleware\PrefixSessionCookie;
use App\Models\Member;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrefixSessionCookieTest extends TestCase
{
    use RefreshDatabase;

    private function handle(string $path): Response
    {
        return app(PrefixSessionCookie::class)->handle(
            Request::create($path),
            fn (): Response => new Response(''),
        );
    }

    public function test_every_path_keeps_the_one_store_and_guard(): void
    {
        config(['session.secure' => true]);
        $prefixed = '__Host-'.config('session.cookie');

        foreach (['/admin', '/admin/members', EndpointResolver::updatePath(), '/filament/exports/1/download', '/dashboard', '/locale'] as $path) {
            $this->handle($path);

            $this->assertSame($prefixed, config('session.cookie'), $path);
            $this->assertSame('sessions', config('session.table'), $path);
            $this->assertSame('member', config('auth.defaults.guard'), $path);
        }
    }

    public function test_the_predicate_matches_the_real_panel_and_package_routes(): void
    {
        $this->assertSame('admin', Filament::getPanel('admin')->getPath());

        $livewireUpdate = Route::getRoutes()->getByName('default-livewire.update');
        $this->assertNotNull($livewireUpdate);
        $this->assertStringStartsWith(ltrim(EndpointResolver::prefix(), '/').'/', $livewireUpdate->uri());

        $export = Route::getRoutes()->getByName('filament.exports.download');
        $this->assertNotNull($export);
        $this->assertStringStartsWith(config('filament.system_route_prefix', 'filament').'/', $export->uri());
    }

    public function test_secure_prefixes_the_session_cookie(): void
    {
        config(['session.secure' => true]);
        $base = config('session.cookie');

        $this->handle('/dashboard');
        $this->assertSame('__Host-'.$base, config('session.cookie'));
    }

    /** @param array<string, mixed> $scope */
    #[DataProvider('scopedCookies')]
    public function test_a_cookie_scoped_beyond_the_host_root_falls_back_to_the_secure_prefix(array $scope): void
    {
        // A browser drops a __Host- cookie that carries a Domain attribute or a path other than /.
        config(['session.secure' => true] + $scope);
        $base = config('session.cookie');

        $this->handle('/dashboard');
        $this->assertSame('__Secure-'.$base, config('session.cookie'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function scopedCookies(): array
    {
        return [
            'parent domain' => [['session.domain' => '.example.test']],
            'sub-path' => [['session.path' => '/sns']],
        ];
    }

    /** @param array<string, mixed> $scope */
    #[DataProvider('emptyScopes')]
    public function test_an_empty_domain_or_path_still_emits_a_host_only_root_cookie(array $scope): void
    {
        config(['session.driver' => 'database', 'session.secure' => true] + $scope);
        $prefixed = '__Host-'.config('session.cookie');

        $header = (string) $this->get('/login')->assertCookie($prefixed)->getCookie($prefixed, false);

        $this->assertStringContainsString('; path=/;', $header);
        $this->assertStringNotContainsString('domain=', $header);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function emptyScopes(): array
    {
        return [
            'empty domain' => [['session.domain' => '']],
            'empty path' => [['session.path' => '']],
            'null path' => [['session.path' => null]],
        ];
    }

    public function test_no_secure_prefix_on_a_plain_http_host(): void
    {
        // A __Secure- cookie without HTTPS is rejected outright, so a dev host — where
        // session.secure is off — must keep the bare name or nobody can log in.
        $this->assertFalse((bool) config('session.secure'));
        $memberBase = config('session.cookie');

        $this->handle('/dashboard');

        $this->assertSame($memberBase, config('session.cookie'));
    }

    public function test_an_already_prefixed_base_name_is_left_untouched(): void
    {
        // An operator may set SESSION_COOKIE to a name that already carries a cookie-name
        // prefix; neither is re-prefixed.
        foreach (['__Secure-custom', '__Host-custom'] as $name) {
            config(['session.secure' => true, 'session.cookie' => $name]);
            // The middleware captures the member base in its constructor, so re-resolve it
            // to pick up this iteration's configured name.
            $this->app->forgetInstance(PrefixSessionCookie::class);

            $this->handle('/dashboard');

            $this->assertSame($name, config('session.cookie'));
        }
    }

    public function test_the_prefixed_cookie_is_secure_and_round_trips_a_login(): void
    {
        config(['session.driver' => 'database', 'session.secure' => true]);
        $prefixed = '__Host-'.config('session.cookie');
        $member = Member::factory()->create();

        $login = $this->post('/login', ['email' => $member->email, 'password' => 'password']);
        $login->assertRedirect();
        $login->assertCookie($prefixed);
        $cookie = $login->getCookie($prefixed, false);
        $this->assertTrue($cookie->isSecure());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $sid = $login->getCookie($prefixed)->getValue();

        // The browser would send the __Host- cookie back over HTTPS; the prefixed name
        // still authenticates.
        $this->freshRequestState();
        $this->withCookie($prefixed, $sid)->get('/dashboard')->assertOk();
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function releasedCookiePrefixes(): array
    {
        return ['host-only' => ['__Host-', null], 'with a domain' => ['__Secure-', 'localhost']];
    }

    /** The session row and cookie are written as 4.0.0-alpha.1 wrote a member's, not by this version's login. */
    #[DataProvider('releasedCookiePrefixes')]
    public function test_a_member_session_from_the_release_before_the_switch_is_signed_in_on_both_realms(string $prefix, ?string $domain): void
    {
        config(['session.driver' => 'database', 'session.secure' => true, 'session.domain' => $domain]);
        $admin = Member::factory()->administrator()->create();
        $login = 'login_member_'.sha1('Illuminate\\Auth\\SessionGuard');
        $sid = str_repeat('a', 40);
        DB::table('sessions')->insert([
            'id' => $sid,
            'user_id' => $admin->getKey(),
            'payload' => base64_encode(json_encode([
                '_token' => str_repeat('t', 40),
                $login => $admin->getKey(),
                $login.'_at' => time(),
                'password_hash_member' => hash_hmac('sha256', $admin->getAuthPassword(), (string) config('app.key')),
            ], JSON_THROW_ON_ERROR)),
            'last_activity' => time(),
        ]);
        $cookie = $prefix.config('session.cookie');

        $this->freshRequestState();
        $this->withCookie($cookie, $sid)->get('/dashboard')->assertOk();
        // Signed in on the admin realm too, which then asks for its own entry.
        foreach (['/admin', '/admin/members'] as $path) {
            $this->freshRequestState();
            $this->withCookie($cookie, $sid)->get($path)->assertRedirect(route('member.admin_entry'));
        }
    }

    public function test_no_livewire_renders_outside_the_admin_realm(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if (str_starts_with($file->getPathname(), app_path('Filament'))) {
                continue;
            }
            if (str_contains($file->getContents(), 'Livewire\\Component')) {
                $offenders[] = $file->getPathname();
            }
        }

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_starts_with($file->getPathname(), resource_path('views/filament'))) {
                continue;
            }
            $contents = $file->getContents();
            if (str_contains($contents, '@livewire') || str_contains($contents, '<livewire:')) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders,
            'Livewire belongs to the admin realm: EnsureAdministrator gates every Livewire endpoint, '
            .'so a member-realm Livewire component would answer only administrators.');
    }
}
