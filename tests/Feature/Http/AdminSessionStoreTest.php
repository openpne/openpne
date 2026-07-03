<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Middleware\UseAdminSessionStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * UseAdminSessionStore pins the session store (cookie + table) and default guard to
 * the request's realm (the member/admin split), and keeps the XSRF-TOKEN cookie
 * member-only. These tests pin the predicate against the real routes so a
 * Filament/Livewire upgrade that moves an endpoint fails here instead of silently
 * splitting a realm across two stores.
 */
class AdminSessionStoreTest extends TestCase
{
    use RefreshDatabase;

    private function handle(string $path): Response
    {
        return app(UseAdminSessionStore::class)->handle(
            Request::create($path),
            fn (): Response => new Response(''),
        );
    }

    public function test_admin_paths_pin_the_admin_store_and_guard(): void
    {
        foreach (['/admin', '/admin/members', EndpointResolver::updatePath(), EndpointResolver::uploadPath(), EndpointResolver::prefix().'/preview-file/photo.png', '/filament/exports/1/download'] as $path) {
            $this->handle($path);

            $this->assertSame(config('session.admin_cookie'), config('session.cookie'), $path);
            $this->assertSame('admin_sessions', config('session.table'), $path);
            $this->assertSame('admin', config('auth.defaults.guard'), $path);
        }
    }

    public function test_member_paths_restore_the_base_store_and_guard(): void
    {
        $baseCookie = config('session.cookie');

        foreach (['/dashboard', '/admin-foo', '/administrator', '/filament-foo', '/locale'] as $path) {
            $this->handle('/admin');
            $this->handle($path);

            $this->assertSame($baseCookie, config('session.cookie'), $path);
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

    public function test_each_realm_sets_its_own_session_cookie(): void
    {
        config(['session.driver' => 'database']);
        // Read once up front: the middleware's realm pin rewrites session.cookie
        // during each request, so config() re-reads here would follow the pin.
        $memberCookie = config('session.cookie');
        $adminCookie = config('session.admin_cookie');

        $this->get('/login')
            ->assertCookie($memberCookie)
            ->assertCookieMissing($adminCookie)
            ->assertCookie('XSRF-TOKEN');

        $this->freshRequestState();

        $this->get('/admin/login')
            ->assertCookie($adminCookie)
            ->assertCookieMissing($memberCookie)
            ->assertCookieMissing('XSRF-TOKEN');
    }

    public function test_the_xsrf_strip_matches_a_configured_cookie_domain(): void
    {
        config(['session.domain' => '.example.test']);

        $response = app(UseAdminSessionStore::class)->handle(Request::create('/admin'), function (): Response {
            $response = new Response('');
            $response->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token', 0, '/', '.example.test'));

            return $response;
        });

        $this->assertSame([], array_values(array_filter(
            $response->headers->getCookies(),
            fn (Cookie $cookie): bool => $cookie->getName() === 'XSRF-TOKEN',
        )));
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
            'Livewire belongs to the admin realm: UseAdminSessionStore routes every Livewire '
            .'endpoint to the admin session store, so a member-realm Livewire component would '
            .'run on the wrong session. Extend the realm predicate before adding one.');
    }
}
