<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\AdminPasswords;
use App\Models\AdminPasswordSetupToken;
use App\Models\AdminUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class AdminPasswordSetupTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    private const PASSWORD = 'chosen-by-them-7';

    protected function setUp(): void
    {
        parent::setUp();

        // A component an earlier test rendered in this process would make Livewire inject its script into this page.
        Livewire::flushState();
    }

    private function issue(AdminUser $admin, int $minutes = 60): string
    {
        return app(AdminPasswords::class)->issueSetupLink($admin, $minutes)[0];
    }

    private function newAdmin(string $username = 'amy'): AdminUser
    {
        $admin = AdminUser::factory()->create(['username' => $username]);
        DB::table('admin_users')->where('id', $admin->getKey())->update(['password' => null, 'remember_token' => null]);

        return $admin->refresh();
    }

    private function submit(string $token, array $fields = []): TestResponse
    {
        return $this->post('/admin/password-setup/'.$token, $fields + [
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);
    }

    private function assertSecretHeaders(TestResponse $response): void
    {
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-transform', $cacheControl);
    }

    public function test_the_link_shows_a_form_for_its_administrator_and_changes_neither_the_link_nor_the_password(): void
    {
        $admin = $this->newAdmin();
        $token = $this->issue($admin);
        $before = AdminPasswordSetupToken::sole()->getAttributes();

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b.*\b(admin_users|admin_password_setup_tokens)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $response = $this->get('/admin/password-setup/'.$token)
            ->assertOk()
            ->assertSee('value="amy"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertDontSee('<script', false);

        $this->assertSecretHeaders($response);
        $this->assertSame([], $writes);
        $this->assertSame($before, AdminPasswordSetupToken::sole()->getAttributes());
        $this->assertNull($admin->fresh()->getRawOriginal('password'));
    }

    public function test_the_session_never_holds_the_token(): void
    {
        config(['session.driver' => 'database']);
        $token = $this->issue($this->newAdmin());

        // The page the session would otherwise keep as its previous URL.
        $this->get('/admin/password-setup/'.$token)->assertOk();
        $this->assertStringNotContainsString($token, (string) session()->previousUrl());
        $this->submit($token, ['password_confirmation' => 'something-else-9'])->assertStatus(422);

        $stored = DB::table('admin_sessions')->pluck('payload')->map(fn (string $payload): string => base64_decode($payload))->implode("\n");
        $this->assertNotSame('', $stored, 'the session was not written, so this proves nothing');
        $this->assertStringNotContainsString($token, $stored);
        $this->assertStringNotContainsString($token, json_encode(session()->all(), JSON_THROW_ON_ERROR));

        // Any other admin-realm page is still recorded, so the skip is the route's own.
        $this->get('/admin/login')->assertOk();
        $this->assertStringEndsWith('/admin/login', (string) session()->previousUrl());
    }

    public function test_every_dead_link_gets_the_same_page(): void
    {
        $admin = $this->newAdmin();
        $replaced = $this->issue($admin);
        $used = $this->issue($admin);
        $this->submit($used)->assertRedirect();

        $other = $this->newAdmin('bea');
        $expired = $this->issue($other, 1);
        $deleted = $this->issue($this->newAdmin('cid'));
        AdminUser::where('username', 'cid')->delete();

        $paths = [
            'unknown' => Str::random(40),
            'replaced' => $replaced,
            'used' => $used,
            'deleted' => $deleted,
            'cut short' => substr($expired, 0, 30),
            'too long' => $expired.'x',
            'not alphanumeric' => substr($expired, 0, 39).'-',
            'missing' => '',
        ];

        $this->travel(2)->minutes();
        $paths['expired'] = $expired;

        $bodies = [];
        foreach ($paths as $reason => $token) {
            foreach (['get', 'post'] as $method) {
                Cache::flush();
                $response = $this->{$method}('/admin/password-setup/'.$token, ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
                $response->assertNotFound();
                $this->assertSecretHeaders($response);
                $bodies["{$reason} {$method}"] = $response->getContent();
            }
        }

        $this->assertCount(1, array_unique($bodies), 'every reason must render one and the same page');
        $this->assertStringNotContainsString('amy', $bodies['used get']);
        $this->assertStringContainsString(__('This link cannot be used'), $bodies['used get']);
        $this->assertFalse(Hash::check(self::PASSWORD, (string) $other->fresh()->password));
    }

    public function test_a_refused_submit_redraws_the_form_and_keeps_the_link(): void
    {
        $admin = $this->newAdmin();
        $token = $this->issue($admin);

        foreach ([['password_confirmation' => 'something-else-9'], ['password' => 'short', 'password_confirmation' => 'short']] as $fields) {
            $response = $this->submit($token, $fields)->assertStatus(422);
            $this->assertSecretHeaders($response);
            $response->assertDontSee(self::PASSWORD)->assertDontSee('something-else-9')->assertSee('fi-fo-field-wrp-error-message', false);
            $this->assertFalse(session()->has('_old_input'));
            $this->assertFalse(session()->has('errors'));
        }

        $this->assertDatabaseCount('admin_password_setup_tokens', 1);
        $this->assertNull($admin->fresh()->getRawOriginal('password'));
    }

    public function test_the_context_word_is_the_stored_username_not_the_submitted_one(): void
    {
        config(['openpne.password.blocklist' => true]);
        $token = $this->issue($this->newAdmin('quokka'));

        $this->submit($token, ['username' => 'someone', 'password' => 'quokka-quokka-1', 'password_confirmation' => 'quokka-quokka-1'])
            ->assertStatus(422);

        $this->assertDatabaseCount('admin_password_setup_tokens', 1);
    }

    public function test_setting_the_password_consumes_the_link_and_ends_every_session(): void
    {
        $this->captureSecurityLog();
        config(['session.driver' => 'database']);
        $admin = $this->newAdmin();
        $admin->forceFill(['remember_token' => Str::random(60)])->save();
        $remember = $admin->remember_token;
        $token = $this->issue($admin);

        // Another administrator signed in on this browser keeps their other devices and remember token.
        $other = AdminUser::factory()->create(['username' => 'bea']);
        $otherRemember = $other->remember_token;
        DB::table('admin_sessions')->insert([
            ['id' => 'amy-device', 'user_id' => $admin->getKey(), 'payload' => base64_encode('{}'), 'last_activity' => time()],
            ['id' => 'bea-device', 'user_id' => $other->getKey(), 'payload' => base64_encode('{}'), 'last_activity' => time()],
        ]);
        $this->actingAs($other, 'admin')->withSession(['marker' => 'this browser']);

        $this->submit($token)->assertRedirect(url('/admin/login'));

        $admin->refresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertNull($admin->password_scheme);
        $this->assertNotSame($remember, $admin->remember_token);
        $this->assertDatabaseCount('admin_password_setup_tokens', 0);
        $this->assertDatabaseMissing('admin_sessions', ['id' => 'amy-device']);
        $this->assertDatabaseHas('admin_sessions', ['id' => 'bea-device']);
        $this->assertSame($otherRemember, $other->fresh()->remember_token);
        $this->assertFalse(session()->has('marker'), 'this browser starts a new session');
        $this->assertNotEmpty(session('filament.notifications'));

        $logged = $this->assertOneSecurityEvent('password.changed');
        $this->assertSame(['admin', 'amy', 'link'], [$logged['guard'], $logged['username'], $logged['via']]);
        $this->assertStringNotContainsString($token, json_encode($this->securityRecords(), JSON_THROW_ON_ERROR));

        // The administrator now signs in with what they chose.
        $this->assertTrue(auth('admin')->validate(['username' => 'amy', 'password' => self::PASSWORD]));
        $this->submit($token)->assertNotFound();
    }

    public function test_two_factor_settings_survive_the_new_password(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'amy']);
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $before = DB::table('admin_users')->where('id', $admin->getKey())->first(['app_authentication_secret', 'app_authentication_recovery_codes']);

        $this->submit($this->issue($admin))->assertRedirect();

        $after = DB::table('admin_users')->where('id', $admin->getKey())->first(['app_authentication_secret', 'app_authentication_recovery_codes']);
        $this->assertEquals($before, $after);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->fresh()->password));
    }

    public function test_the_page_loads_nothing_from_another_origin(): void
    {
        $html = $this->get('/admin/password-setup/'.$this->issue($this->newAdmin()))->assertOk()->getContent();

        preg_match_all('/\b(?:src|href|action)="([^"]*)"/', (string) $html, $matches);
        $this->assertNotEmpty($matches[1]);
        $origin = rtrim(url('/'), '/');
        foreach ($matches[1] as $reference) {
            $this->assertTrue(str_starts_with($reference, $origin.'/') || (str_starts_with($reference, '/') && ! str_starts_with($reference, '//')), $reference);
        }
    }

    public function test_opening_and_submitting_are_limited_apart(): void
    {
        $this->captureSecurityLog();
        $admin = $this->newAdmin();
        $token = $this->issue($admin);

        // Signed in as an administrator, whose id must not be logged as a member's.
        $this->actingAs(AdminUser::factory()->create(['username' => 'bea']), 'admin');

        for ($i = 0; $i < 5; $i++) {
            $this->get('/admin/password-setup/'.$token)->assertOk();
        }
        $limited = $this->get('/admin/password-setup/'.$token)->assertStatus(429);
        $this->assertStringNotContainsString($token, (string) $limited->getContent());

        $logged = $this->assertOneSecurityEvent('throttle.hit');
        $this->assertSame('admin.password_setup', $logged['route']);
        $this->assertArrayNotHasKey('member_id', $logged);

        $this->submit($token, ['password_confirmation' => 'something-else-9'])->assertStatus(422);
    }

    public function test_an_error_page_on_this_path_does_not_echo_the_token_or_password(): void
    {
        // The test runner skips the CSRF check, so the 419 is rendered the way the handler would.
        $token = $this->issue($this->newAdmin());
        $request = Request::create('/admin/password-setup/'.$token, 'POST', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        $this->app->instance('request', $request);

        foreach ([419, 429] as $status) {
            $body = (string) app(ExceptionHandler::class)->render($request, new HttpException($status))->getContent();
            $this->assertStringNotContainsString($token, $body);
            $this->assertStringNotContainsString(self::PASSWORD, $body);
        }
    }
}
