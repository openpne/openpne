<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\SessionGuard;
use App\Models\AdminUser;
use App\Models\Member;
use App\Session\MemberSessionLifetime;
use App\Session\RealmDatabaseSessionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MemberSessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST_MINUTES = 120;

    private const MEMBER_MINUTES = 43200;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml runs on the array driver, where every lifetime is the framework's.
        config(['session.driver' => 'database']);
        $this->freezeTime();
    }

    private function handler(string $table): RealmDatabaseSessionHandler
    {
        return new RealmDatabaseSessionHandler(
            DB::connection(),
            $table,
            self::GUEST_MINUTES,
            $this->app,
            $table === 'admin_sessions' ? null : self::MEMBER_MINUTES,
        );
    }

    private function seedRow(string $table, string $id, ?int $userId, int $idleMinutes): void
    {
        DB::table($table)->insert([
            'id' => $id,
            'user_id' => $userId,
            'payload' => base64_encode('payload'),
            'last_activity' => now()->subMinutes($idleMinutes)->getTimestamp(),
        ]);
    }

    private function minutesUntilExpiry(TestResponse $response, string $cookie): int
    {
        $found = $response->getCookie($cookie, false);
        $this->assertNotNull($found, $cookie);

        return (int) round(($found->getExpiresTime() - now()->getTimestamp()) / 60);
    }

    /** actingAs() builds the store before the realm pin, so the cookie may carry either realm's name. */
    private function sessionMinutes(TestResponse $response): int
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_ends_with($cookie->getName(), '-session')) {
                return (int) round(($cookie->getExpiresTime() - now()->getTimestamp()) / 60);
            }
        }

        $this->fail('The response carries no session cookie.');
    }

    private function login(Member $member): TestResponse
    {
        return $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect();
    }

    private function loginKey(): string
    {
        return Auth::guard('member')->getName();
    }

    public function test_a_row_naming_a_member_outlives_the_guest_lifetime(): void
    {
        $this->seedRow('sessions', 'member', 7, self::GUEST_MINUTES + 1);
        $this->seedRow('sessions', 'member-stale', 7, self::MEMBER_MINUTES + 1);
        $this->seedRow('sessions', 'guest', null, self::GUEST_MINUTES + 1);

        $this->assertSame('payload', $this->handler('sessions')->read('member'));
        $this->assertSame('', $this->handler('sessions')->read('member-stale'));
        $this->assertSame('', $this->handler('sessions')->read('guest'));
    }

    public function test_an_admin_row_keeps_the_framework_lifetime(): void
    {
        $this->seedRow('admin_sessions', 'admin', 7, self::GUEST_MINUTES + 1);

        $this->assertSame('', $this->handler('admin_sessions')->read('admin'));
    }

    public function test_the_sweep_holds_each_row_to_its_own_lifetime(): void
    {
        $this->seedRow('sessions', 'guest-live', null, self::GUEST_MINUTES - 1);
        $this->seedRow('sessions', 'guest-stale', null, self::GUEST_MINUTES + 1);
        $this->seedRow('sessions', 'member-live', 7, self::MEMBER_MINUTES - 1);
        $this->seedRow('sessions', 'member-stale', 7, self::MEMBER_MINUTES + 1);

        $this->assertSame(2, $this->handler('sessions')->gc(self::GUEST_MINUTES * 60));

        $this->assertSame(['guest-live', 'member-live'], DB::table('sessions')->orderBy('id')->pluck('id')->all());
    }

    public function test_the_session_store_is_built_on_the_realm_handler(): void
    {
        $this->get('/login');

        $this->assertInstanceOf(RealmDatabaseSessionHandler::class, $this->app['session.store']->getHandler());
    }

    public function test_the_admin_realm_store_carries_no_member_lifetime(): void
    {
        $this->seedRow('admin_sessions', 'admin', 7, self::GUEST_MINUTES + 1);

        $this->get('/admin/login');

        $this->assertSame('', $this->app['session.store']->getHandler()->read('admin'));
    }

    public function test_the_member_realm_store_carries_the_member_lifetime(): void
    {
        $this->seedRow('sessions', 'member', 7, self::GUEST_MINUTES + 1);

        $this->get('/login');

        $this->assertSame('payload', $this->app['session.store']->getHandler()->read('member'));
    }

    public function test_signing_in_cannot_shorten_a_session(): void
    {
        config(['session.member_lifetime' => 10]);

        $this->assertSame(self::GUEST_MINUTES, MemberSessionLifetime::idleMinutes());
    }

    public function test_the_absolute_lifetime_is_never_below_the_idle_lifetime(): void
    {
        config(['session.member_absolute_lifetime' => 0]);

        $this->assertSame(self::MEMBER_MINUTES, MemberSessionLifetime::absoluteMinutes());
    }

    public function test_the_guard_reads_the_configured_absolute_lifetime(): void
    {
        config(['session.member_absolute_lifetime' => self::MEMBER_MINUTES + 10]);
        $member = Member::factory()->create();

        $this->withSession([
            $this->loginKey() => $member->getKey(),
            $this->loginKey().'_at' => now()->subMinutes(self::MEMBER_MINUTES + 11)->getTimestamp(),
        ])->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_signed_in_response_carries_both_cookies_for_the_member_lifetime(): void
    {
        $response = $this->login(Member::factory()->create());

        $this->assertSame(self::MEMBER_MINUTES, $this->minutesUntilExpiry($response, (string) config('session.cookie')));
        $this->assertSame(self::MEMBER_MINUTES, $this->minutesUntilExpiry($response, 'XSRF-TOKEN'));
    }

    public function test_a_guest_response_keeps_the_framework_lifetime(): void
    {
        $response = $this->get('/login');

        $this->assertSame(self::GUEST_MINUTES, $this->minutesUntilExpiry($response, (string) config('session.cookie')));
        $this->assertSame(self::GUEST_MINUTES, $this->minutesUntilExpiry($response, 'XSRF-TOKEN'));
    }

    public function test_the_admin_realm_keeps_the_framework_lifetime_for_a_signed_in_member(): void
    {
        $response = $this->actingAs(Member::factory()->create(), 'member')->get('/admin/login');

        $this->assertSame(self::GUEST_MINUTES, $this->sessionMinutes($response));
    }

    public function test_a_signed_in_administrator_keeps_the_framework_lifetime(): void
    {
        $response = $this->actingAs(AdminUser::factory()->create(), 'admin')->get('/admin');

        $this->assertSame(self::GUEST_MINUTES, $this->sessionMinutes($response));
    }

    public function test_another_driver_keeps_the_framework_lifetime(): void
    {
        config(['session.driver' => 'array']);

        $response = $this->login(Member::factory()->create());

        $this->assertSame(self::GUEST_MINUTES, $this->minutesUntilExpiry($response, (string) config('session.cookie')));
        $this->assertSame(self::GUEST_MINUTES, $this->minutesUntilExpiry($response, 'XSRF-TOKEN'));
    }

    public function test_expire_on_close_stands(): void
    {
        config(['session.expire_on_close' => true]);

        $response = $this->login(Member::factory()->create());

        $this->assertSame(0, $response->getCookie((string) config('session.cookie'), false)->getExpiresTime());
        $this->assertSame(self::GUEST_MINUTES, $this->minutesUntilExpiry($response, 'XSRF-TOKEN'));
    }

    public function test_a_login_stamps_the_session(): void
    {
        $this->login(Member::factory()->create())->assertSessionHas($this->loginKey().'_at', now()->getTimestamp());
    }

    public function test_a_session_older_than_the_absolute_lifetime_is_a_guest(): void
    {
        $member = Member::factory()->create();
        $session = [
            $this->loginKey() => $member->getKey(),
            $this->loginKey().'_at' => now()->subMinutes(576001)->getTimestamp(),
        ];

        $this->withSession($session + ['_token' => 'standing-token'])->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest('member');
        // Kept, so a POST that finds the login expired passes the CSRF check and lands on the login.
        $this->assertSame('standing-token', session()->token());

        $this->freshRequestState();
        $this->withSession($session)->post('/member/config/password', [
            'current_password' => 'password', 'password' => 'new-secret-pass', 'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect('/login');
        $this->assertTrue(password_verify('password', $member->fresh()->password));
    }

    public function test_a_session_inside_the_absolute_lifetime_stays_signed_in(): void
    {
        $member = Member::factory()->create();

        $this->withSession([
            $this->loginKey() => $member->getKey(),
            $this->loginKey().'_at' => now()->subMinutes(575999)->getTimestamp(),
        ])->get('/dashboard')->assertOk();

        $this->assertAuthenticatedAs($member, 'member');
    }

    public function test_a_session_without_a_stamp_starts_its_clock(): void
    {
        $member = Member::factory()->create();

        $this->withSession([$this->loginKey() => $member->getKey()])
            ->get('/dashboard')
            ->assertOk()
            ->assertSessionHas($this->loginKey().'_at', now()->getTimestamp());
    }

    public function test_the_admin_guard_has_no_absolute_lifetime(): void
    {
        $admin = AdminUser::factory()->create();
        $key = Auth::guard('admin')->getName();

        $this->withSession([$key => $admin->getKey(), $key.'_at' => 1])->get('/admin')->assertOk();
    }

    /**
     * Member names no remember-token column, so the member guard alone cannot show what its
     * recaller switch does; the administrators' provider still stores a token.
     */
    public function test_a_guard_with_the_recaller_off_ignores_a_cookie_its_provider_would_honour(): void
    {
        $admin = AdminUser::factory()->create(['remember_token' => 'stored-token']);
        $cookie = fn (SessionGuard $guard): array => [$guard->getRecallerName() => $admin->getKey().'|stored-token|'.$admin->getAuthPassword()];
        $build = function (bool $recaller) use ($cookie): SessionGuard {
            $guard = SessionGuard::make($this->app, 'probe', ['provider' => 'admins', 'recaller' => $recaller]);
            $guard->setRequest(Request::create('/', 'GET', [], $cookie($guard)));

            return $guard;
        };

        // In this order: a restore writes the login into the session both guards share.
        $this->assertFalse($build(false)->check());
        $this->assertTrue($build(true)->check());
    }

    public function test_a_once_valid_recaller_restores_nobody_and_is_cleared(): void
    {
        $member = Member::factory()->create();
        DB::table('members')->where('id', $member->getKey())->update(['remember_token' => 'stored-token']);
        $recaller = Auth::guard('member')->getRecallerName();

        $response = $this->withCookie($recaller, $member->getKey().'|stored-token|'.$member->getAuthPassword())
            ->get('/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest('member');
        $response->assertCookieExpired($recaller);
    }
}
