<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\AdminPasswords;
use App\Auth\PasswordScheme;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\AdminUsers\Pages\EditAdminUser;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AdminPasswordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    private function passwords(): AdminPasswords
    {
        return app(AdminPasswords::class);
    }

    private function passwordless(string $username = 'amy'): AdminUser
    {
        $this->artisan('openpne:admin:create', ['username' => $username, '--without-password' => true])->assertSuccessful();

        return AdminUser::where('username', $username)->sole();
    }

    public function test_an_administrator_without_a_password_cannot_sign_in_by_any_path(): void
    {
        $admin = $this->passwordless();

        foreach (['', 'anything', 'null'] as $attempt) {
            Livewire::test(Login::class)
                ->fillForm(['email' => 'amy', 'password' => $attempt])
                ->call('authenticate')
                ->assertHasFormErrors();
            $this->assertFalse(Auth::guard('admin')->attempt(['username' => 'amy', 'password' => $attempt]));
        }
        $this->assertGuest('admin');

        // A session naming the administrator, and a remember cookie carrying their stored token: neither
        // the session hash check nor the cookie's hash segment has a password to compare against.
        $guard = Auth::guard('admin');
        foreach ([null, ''] as $password) {
            DB::table('admin_users')->where('id', $admin->getKey())->update(['password' => $password, 'remember_token' => 'stored-token']);

            $this->app['auth']->forgetGuards();
            $this->withSession([$guard->getName() => $admin->getKey()])->get('/admin')->assertRedirect(url('/admin/login'));
            $this->flushSession();

            $this->app['auth']->forgetGuards();
            $this->withCookie($guard->getRecallerName(), $admin->getKey().'|stored-token|'.$password)
                ->get('/admin')
                ->assertRedirect(url('/admin/login'));
            $this->assertGuest('admin');
        }
    }

    public function test_a_failure_part_way_leaves_the_password_the_link_and_the_sessions_as_they_were(): void
    {
        config(['session.driver' => 'database']);
        $admin = AdminUser::factory()->create(['username' => 'amy', 'password' => 'old-pass-1234']);
        $before = DB::table('admin_users')->where('id', $admin->getKey())->first();
        [$token] = $this->passwords()->issueSetupLink($admin, 60);

        // The session purge is the last write, so the password and link writes before it must roll back.
        DB::beforeExecuting(function (string $query): void {
            if (preg_match('/^delete from ["`]admin_sessions["`]/', $query) === 1) {
                throw new RuntimeException('session purge refused');
            }
        });

        try {
            $this->passwords()->consumeSetupLink($token, 'new-pass-1234');
            $this->fail('the purge should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('session purge refused', $e->getMessage());
        }

        $this->assertEquals($before, DB::table('admin_users')->where('id', $admin->getKey())->first());
        $this->assertDatabaseCount('admin_password_setup_tokens', 1);
        $this->assertNotNull($this->passwords()->setupLinkOwner($token));
    }

    public function test_a_used_link_cannot_be_used_again(): void
    {
        $admin = $this->passwordless();
        [$token] = $this->passwords()->issueSetupLink($admin, 60);

        $this->assertTrue($this->passwords()->consumeSetupLink($token, 'first-choice-1')?->is($admin));
        $this->assertNull($this->passwords()->consumeSetupLink($token, 'second-choice-1'));
        $this->assertTrue(Hash::check('first-choice-1', $admin->fresh()->password));
    }

    public function test_a_link_that_expires_between_the_lookup_and_the_locked_delete_is_not_used(): void
    {
        $admin = $this->passwordless();
        [$token] = $this->passwords()->issueSetupLink($admin, 1);
        $this->travel(59)->seconds();

        DB::listen(function ($query): void {
            if (preg_match('/^select .*admin_password_setup_tokens/', $query->sql) === 1) {
                $this->travel(2)->seconds();
            }
        });

        $this->assertNull($this->passwords()->consumeSetupLink($token, 'too-late-pass-1'));
        $this->assertNull($admin->fresh()->getRawOriginal('password'));
    }

    public function test_the_cli_reset_voids_an_unused_link(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'amy']);
        [$token] = $this->passwords()->issueSetupLink($admin, 60);

        $this->artisan('openpne:admin:reset-password', ['username' => 'amy'])
            ->expectsQuestion('Password', 'new-pass-1234')
            ->expectsQuestion('Confirm password', 'new-pass-1234')
            ->assertSuccessful();

        $this->assertDatabaseCount('admin_password_setup_tokens', 0);
        $this->assertNull($this->passwords()->consumeSetupLink($token, 'link-choice-1'));
        $this->assertTrue(Hash::check('new-pass-1234', $admin->fresh()->password));
    }

    public function test_the_panel_change_voids_an_unused_link_and_keeps_the_administrator_signed_in(): void
    {
        $me = AdminUser::factory()->create(['username' => 'me', 'password' => 'original-pass-1']);
        [$token] = $this->passwords()->issueSetupLink($me, 60);
        $this->actingAs($me, 'admin');
        $this->get('/admin')->assertOk();

        Livewire::test(EditAdminUser::class, ['record' => $me->getKey()])
            ->callAction('changePassword', [
                'current_password' => 'original-pass-1',
                'password' => 'new-strong-pass-1',
                'password_confirmation' => 'new-strong-pass-1',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseCount('admin_password_setup_tokens', 0);
        $this->assertNull($this->passwords()->consumeSetupLink($token, 'link-choice-1'));

        // The next request loads the administrator afresh, as a real one would.
        Auth::guard('admin')->setUser($me->fresh());
        $this->get('/admin')->assertOk();
    }

    public function test_the_panel_change_writes_nothing_when_a_link_set_the_password_after_the_current_one_was_checked(): void
    {
        $me = AdminUser::factory()->create(['username' => 'me', 'password' => 'original-pass-1', 'remember_token' => 'shared-token']);
        $this->actingAs($me, 'admin');
        // The guard keeps the hash it loaded, as a request already past its current-password check does.
        [$token] = $this->passwords()->issueSetupLink($me, 60);
        $this->passwords()->consumeSetupLink($token, 'set-by-link-1');
        $rowBefore = DB::table('admin_users')->where('id', $me->getKey())->first();

        Livewire::test(EditAdminUser::class, ['record' => $me->getKey()])
            ->callAction('changePassword', [
                'current_password' => 'original-pass-1',
                'password' => 'new-strong-pass-1',
                'password_confirmation' => 'new-strong-pass-1',
            ])
            ->assertRedirect(Filament::getLoginUrl());

        $this->assertEquals($rowBefore, DB::table('admin_users')->where('id', $me->getKey())->first(), 'only this device is signed out');
        $this->assertTrue(Hash::check('set-by-link-1', $me->fresh()->password));
        $this->assertGuest('admin');
    }

    public function test_an_imported_administrator_stays_signed_in_after_the_first_login_rewrites_the_hash(): void
    {
        DB::table('admin_users')->insert([
            'username' => 'legacy',
            'password' => Hash::make(md5('secret')),
            'password_scheme' => PasswordScheme::Md5Bcrypt->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'legacy', 'password' => 'secret'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $row = DB::table('admin_users')->where('username', 'legacy')->first();
        $this->assertNull($row->password_scheme);
        $this->assertSame(Auth::guard('admin')->hashPasswordForCookie($row->password), session('password_hash_admin'));

        Auth::guard('admin')->setUser(AdminUser::where('username', 'legacy')->sole());
        $this->get('/admin')->assertOk();
    }

    public function test_a_login_rehash_does_not_overwrite_a_password_set_meanwhile(): void
    {
        DB::table('admin_users')->insert([
            'username' => 'legacy',
            'password' => $wrapped = Hash::make(md5('secret')),
            'password_scheme' => PasswordScheme::Md5Bcrypt->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $provider = Auth::guard('admin')->getProvider();
        $read = $provider->retrieveByCredentials(['username' => 'legacy']);
        $this->assertTrue($provider->validateCredentials($read, ['password' => 'secret']));

        // A setup link is used between this login's read and its rewrite.
        $admin = AdminUser::where('username', 'legacy')->sole();
        [$token] = $this->passwords()->issueSetupLink($admin, 60);
        $this->passwords()->consumeSetupLink($token, 'set-by-link-1');

        $provider->rehashPasswordIfRequired($read, ['password' => 'secret']);

        $this->assertTrue(Hash::check('set-by-link-1', $admin->fresh()->password));
        $this->assertNull($admin->fresh()->password_scheme);
        $this->assertSame($wrapped, $read->getAuthPassword(), 'a lost race leaves the in-memory user stale, for AuthenticateSession to end');
    }
}
