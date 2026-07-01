<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\LegacyEloquentUserProvider;
use App\Filament\Pages\Auth\Login;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An administrator carried over from OpenPNE 3 has a bare MD5 password (the upgrade lands it verbatim).
 * The `admins` guard's LegacyEloquentUserProvider lets them log in with it and rehashes it to bcrypt,
 * and — critically — validateCredentials() itself never writes, so the rehash only happens once the
 * login is authorized.
 */
class AdminLegacyLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    /** Insert an admin whose password is a raw OpenPNE 3 MD5 (the factory would bcrypt it via the cast). */
    private function seedLegacyAdmin(string $username, string $plaintext): string
    {
        $md5 = md5($plaintext);
        DB::table('admin_users')->insert([
            'username' => $username,
            'password' => $md5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $md5;
    }

    public function test_migrated_admin_logs_in_with_the_legacy_md5_and_it_becomes_bcrypt(): void
    {
        $md5 = $this->seedLegacyAdmin('legacy', 'secret');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'legacy', 'password' => 'secret'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $admin = AdminUser::where('username', 'legacy')->first();
        $this->assertAuthenticatedAs($admin, 'admin');

        // The weak hash is gone after the first successful login.
        $this->assertNotSame($md5, $admin->password);
        $this->assertTrue(Hash::isHashed($admin->password));
        $this->assertTrue(Hash::check('secret', $admin->password));
    }

    public function test_migrated_admin_cannot_log_in_with_a_wrong_password(): void
    {
        $md5 = $this->seedLegacyAdmin('legacy', 'secret');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'legacy', 'password' => 'wrong'])
            ->call('authenticate')
            ->assertHasFormErrors();

        $this->assertGuest('admin');
        // A failed attempt must not rewrite the stored hash.
        $this->assertSame($md5, AdminUser::where('username', 'legacy')->first()->password);
    }

    public function test_validate_credentials_is_side_effect_free(): void
    {
        $md5 = $this->seedLegacyAdmin('legacy', 'secret');
        $admin = AdminUser::where('username', 'legacy')->first();
        $provider = new LegacyEloquentUserProvider(app('hash'), AdminUser::class);

        $this->assertTrue($provider->validateCredentials($admin, ['password' => 'secret']));
        $this->assertFalse($provider->validateCredentials($admin, ['password' => 'wrong']));

        // Verifying the legacy hash must not persist a rehash — that is the guard's job, after authorization.
        $this->assertSame($md5, AdminUser::where('username', 'legacy')->first()->password);
    }

    public function test_existing_bcrypt_admin_still_logs_in(): void
    {
        AdminUser::factory()->create(['username' => 'current']); // factory bcrypts 'password'

        Livewire::test(Login::class)
            ->fillForm(['email' => 'current', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated('admin');
    }
}
