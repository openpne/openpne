<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\LegacyEloquentUserProvider;
use App\Auth\PasswordScheme;
use App\Filament\Pages\Auth\Login;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An administrator carried over from OpenPNE 3 has a wrapped password — bcrypt over the
 * legacy MD5 hex, flagged password_scheme=md5_bcrypt by the upgrade's wrap pass. The
 * `admins` guard's LegacyEloquentUserProvider verifies it (md5 first) and retires the
 * wrapper to a plain bcrypt after authorization; validateCredentials() itself never
 * writes, and a bare unwrapped MD5 authenticates nobody.
 */
class AdminLegacyLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    /** Insert an admin as the upgrade's wrap pass leaves them: bcrypt(md5hex) + the scheme flag. */
    private function seedWrappedAdmin(string $username, string $plaintext): string
    {
        $wrapped = Hash::make(md5($plaintext));
        DB::table('admin_users')->insert([
            'username' => $username,
            'password' => $wrapped,
            'password_scheme' => PasswordScheme::Md5Bcrypt->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $wrapped;
    }

    public function test_migrated_admin_logs_in_with_the_wrapped_hash_and_it_becomes_a_plain_bcrypt(): void
    {
        $wrapped = $this->seedWrappedAdmin('legacy', 'secret');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'legacy', 'password' => 'secret'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $admin = AdminUser::where('username', 'legacy')->first();
        $this->assertAuthenticatedAs($admin, 'admin');

        // The wrapper — and its flag — are gone after the first successful login.
        $this->assertNotSame($wrapped, $admin->password);
        $this->assertTrue(Hash::check('secret', $admin->password));
        $this->assertNull($admin->password_scheme);
    }

    public function test_migrated_admin_cannot_log_in_with_a_wrong_password(): void
    {
        $wrapped = $this->seedWrappedAdmin('legacy', 'secret');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'legacy', 'password' => 'wrong'])
            ->call('authenticate')
            ->assertHasFormErrors();

        $this->assertGuest('admin');
        // A failed attempt must not rewrite the stored hash or the flag.
        $admin = AdminUser::where('username', 'legacy')->first();
        $this->assertSame($wrapped, $admin->password);
        $this->assertSame(PasswordScheme::Md5Bcrypt->value, $admin->password_scheme);
    }

    public function test_validate_credentials_is_side_effect_free(): void
    {
        $wrapped = $this->seedWrappedAdmin('legacy', 'secret');
        $admin = AdminUser::where('username', 'legacy')->first();
        $provider = new LegacyEloquentUserProvider(app('hash'), AdminUser::class);

        $this->assertTrue($provider->validateCredentials($admin, ['password' => 'secret']));
        $this->assertFalse($provider->validateCredentials($admin, ['password' => 'wrong']));

        // Verifying the wrapped hash must not persist a rehash — that is the guard's job, after authorization.
        $this->assertSame($wrapped, AdminUser::where('username', 'legacy')->first()->password);
    }

    public function test_a_bare_unwrapped_md5_no_longer_authenticates(): void
    {
        // The wrap pass converts every imported MD5 before an upgrade completes
        // (verify-upgrade holds the cutover on it); a bare hash at rest must not be
        // accepted, even with the right password.
        DB::table('admin_users')->insert([
            'username' => 'bare',
            'password' => md5('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'bare', 'password' => 'secret'])
            ->call('authenticate')
            ->assertHasFormErrors();

        $this->assertGuest('admin');
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
