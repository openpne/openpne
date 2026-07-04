<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P0 regression coverage for the dual-guard session cross-bleed: the member and admin
 * realms keep separate session stores (UseAdminSessionStore), so `url.intended`
 * cannot redirect a login across realms and either side's logout leaves the other
 * side signed in.
 *
 * Requests run on the database driver and carry both cookies at once (withCookie
 * persists within a test), modelling one browser's cookie jar. freshRequestState()
 * between requests plays the role of the next request hitting a fresh worker.
 */
class AdminSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    private string $memberCookie;

    private string $adminCookie;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        // Read once up front: the middleware's realm pin rewrites session.cookie
        // during each admin-realm request, so later config() re-reads would follow it.
        $this->memberCookie = config('session.cookie');
        $this->adminCookie = config('session.admin_cookie');
    }

    public function test_intended_urls_do_not_cross_between_realms(): void
    {
        $response = $this->get('/admin');
        $response->assertRedirect('/admin/login');
        $adminSid = $response->getCookie($this->adminCookie)->getValue();

        $this->freshRequestState();
        $response = $this->withCookie($this->adminCookie, $adminSid)->get('/dashboard');
        $response->assertRedirect('/login');
        $memberSid = $response->getCookie($this->memberCookie)->getValue();

        $member = Member::factory()->create();
        $this->freshRequestState();
        $this->withCookie($this->memberCookie, $memberSid)
            ->post('/login', ['email' => $member->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        // The admin store's own intended URL is untouched by the member-side login.
        $this->assertStringContainsString('/admin', (string) $this->adminStore($adminSid)->get('url.intended'));
    }

    public function test_member_logout_leaves_the_admin_session_alive(): void
    {
        $memberSid = $this->loginAsMember();
        $admin = AdminUser::factory()->create();
        $adminSid = $this->seedAdminSession($admin);

        $this->freshRequestState();
        $this->withCookie($this->memberCookie, $memberSid)
            ->withCookie($this->adminCookie, $adminSid)
            ->post('/logout')
            ->assertRedirect('/');

        $this->freshRequestState();
        $this->withCookie($this->adminCookie, $adminSid)->get('/admin')->assertOk();
        $this->assertTrue(DB::table('admin_sessions')->where('id', $adminSid)->exists());
    }

    public function test_admin_logout_leaves_the_member_session_alive(): void
    {
        $memberSid = $this->loginAsMember();
        $admin = AdminUser::factory()->create();
        $adminSid = $this->seedAdminSession($admin);

        $this->freshRequestState();
        $this->withCookie($this->adminCookie, $adminSid)->post('/admin/logout')->assertRedirect();
        $this->assertFalse(DB::table('admin_sessions')->where('id', $adminSid)->exists());

        $this->freshRequestState();
        $this->withCookie($this->memberCookie, $memberSid)->get('/dashboard')->assertOk();
        $this->assertTrue(DB::table('sessions')->where('id', $memberSid)->exists());
    }

    public function test_the_admin_locale_switch_writes_to_the_admin_store(): void
    {
        // The 419 this relocation prevents cannot be asserted directly — CSRF token
        // matching is bypassed under unit tests — so pin its precondition instead:
        // the write lands in the store whose page embedded the token.
        $admin = AdminUser::factory()->create();
        $adminSid = $this->seedAdminSession($admin);

        $this->freshRequestState();
        $this->withCookie($this->adminCookie, $adminSid)
            ->post('/admin/locale/session', ['locale' => 'en'])
            ->assertNoContent();

        $this->assertSame('en', $this->adminStore($adminSid)->get('locale'));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_admin_realm_requests_stamp_the_admin_user_id(): void
    {
        // Regression for the default-guard pin: this route never runs Filament's
        // Authenticate (which would shouldUse the admin guard), so without the pin the
        // session handler stamps user_id from the member guard — null here — and the
        // row would escape a future purge-by-admin-id.
        $admin = AdminUser::factory()->create();
        $adminSid = $this->seedAdminSession($admin);

        $this->freshRequestState();
        $this->withCookie($this->adminCookie, $adminSid)
            ->post('/admin/locale/session', ['locale' => 'en'])
            ->assertNoContent();

        $this->assertSame($admin->getKey(), (int) DB::table('admin_sessions')->where('id', $adminSid)->value('user_id'));
    }

    /** Log a member in through the real flow and return the resulting session id. */
    private function loginAsMember(): string
    {
        $member = Member::factory()->create();
        $response = $this->post('/login', ['email' => $member->email, 'password' => 'password']);
        $response->assertRedirect();

        return $response->getCookie($this->memberCookie)->getValue();
    }

    /**
     * Write an authenticated admin session through the production write path (handler +
     * Store, matching serialization). Filament's login page is a Livewire component whose
     * test harness never emits cookies, so the row is seeded directly instead.
     */
    private function seedAdminSession(AdminUser $admin): string
    {
        $store = $this->adminStore();
        $store->put($this->app['auth']->guard('admin')->getName(), $admin->getKey());
        // Filament's AuthenticateSession compares this against the current password hash.
        $store->put('password_hash_admin', $admin->getAuthPassword());
        $store->save();

        return $store->getId();
    }

    private function adminStore(?string $id = null): Store
    {
        $store = new Store(
            $this->adminCookie,
            new DatabaseSessionHandler(DB::connection(), config('session.admin_table'), (int) config('session.lifetime')),
            $id,
            config('session.serialization', 'php'),
        );

        if ($id !== null) {
            $store->start();
        }

        return $store;
    }
}
