<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResetAdminPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_resets_an_existing_administrators_password(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'amy', 'password' => 'old-pass-1234']);

        $this->artisan('openpne:admin:reset-password', ['username' => 'amy'])
            ->expectsQuestion('Password', 'new-pass-1234')
            ->expectsQuestion('Confirm password', 'new-pass-1234')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertTrue(Hash::check('new-pass-1234', $admin->password));
    }

    public function test_reset_revokes_the_administrators_sessions_and_remember_token(): void
    {
        // Lockout recovery doubles as compromise recovery: whoever held the old
        // credential must not stay signed in through an existing session or cookie.
        config(['session.driver' => 'database']);
        $admin = AdminUser::factory()->create(['username' => 'amy', 'password' => 'old-pass-1234']);
        $admin->forceFill(['remember_token' => Str::random(60)])->save();
        $before = $admin->remember_token;

        DB::table('admin_sessions')->insert([
            'id' => 'stolen-device', 'user_id' => $admin->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        $this->artisan('openpne:admin:reset-password', ['username' => 'amy'])
            ->expectsQuestion('Password', 'new-pass-1234')
            ->expectsQuestion('Confirm password', 'new-pass-1234')
            ->assertSuccessful();

        $this->assertDatabaseMissing('admin_sessions', ['id' => 'stolen-device']);
        $this->assertNotSame($before, $admin->fresh()->remember_token);
    }

    public function test_fails_for_an_unknown_username(): void
    {
        $this->artisan('openpne:admin:reset-password', ['username' => 'ghost'])
            ->assertFailed();
    }
}
