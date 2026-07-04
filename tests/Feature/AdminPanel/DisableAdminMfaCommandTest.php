<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\AdminAppAuthentication;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lockout recovery: openpne:admin:disable-mfa clears an administrator's TOTP secret and
 * recovery codes and revokes every session — gated by server access, like the password reset.
 */
class DisableAdminMfaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_disables_mfa_and_revokes_sessions(): void
    {
        config(['session.driver' => 'database']);
        $admin = AdminUser::factory()->create(['username' => 'amy']);
        $provider = AdminAppAuthentication::make()->recoverable();
        $admin->saveAppAuthenticationSecret($provider->generateSecret());
        $provider->saveRecoveryCodes($admin, $provider->generateRecoveryCodes());
        $admin->forceFill(['remember_token' => Str::random(60)])->save();
        $before = $admin->fresh()->remember_token;

        DB::table('admin_sessions')->insert([
            'id' => 'device', 'user_id' => $admin->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        $this->artisan('openpne:admin:disable-mfa', ['username' => 'amy'])->assertSuccessful();

        $admin->refresh();
        $this->assertNull($admin->getAppAuthenticationSecret());
        $this->assertNull($admin->getAppAuthenticationRecoveryCodes());
        $this->assertDatabaseMissing('admin_sessions', ['id' => 'device']);
        $this->assertNotSame($before, $admin->remember_token);
    }

    public function test_it_fails_for_an_unknown_username(): void
    {
        $this->artisan('openpne:admin:disable-mfa', ['username' => 'ghost'])->assertFailed();
    }
}
