<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_administrator_with_a_hashed_password(): void
    {
        $this->artisan('openpne:admin:create', ['username' => 'bob'])
            ->expectsQuestion('Password', 'strong-pass-1')
            ->expectsQuestion('Confirm password', 'strong-pass-1')
            ->assertSuccessful();

        $admin = AdminUser::where('username', 'bob')->firstOrFail();
        $this->assertTrue(Hash::check('strong-pass-1', $admin->password));
    }

    public function test_a_unicode_space_around_the_username_is_trimmed(): void
    {
        $this->artisan('openpne:admin:create', ['username' => "\u{3000}root\u{00A0}"])
            ->expectsQuestion('Password', 'strong-pass-1')
            ->expectsQuestion('Confirm password', 'strong-pass-1')
            ->assertSuccessful();

        $this->assertSame('root', AdminUser::sole()->username);
    }

    public function test_rejects_a_duplicate_username(): void
    {
        AdminUser::factory()->create(['username' => 'bob']);

        $this->artisan('openpne:admin:create', ['username' => 'bob'])
            ->assertFailed();
    }

    public function test_without_password_leaves_the_password_unset(): void
    {
        $this->artisan('openpne:admin:create', ['username' => 'new', '--without-password' => true])
            ->expectsOutputToContain('openpne:admin:password-link new')
            ->assertSuccessful();

        $admin = AdminUser::sole();
        $this->assertSame('new', $admin->username);
        $this->assertNull($admin->getRawOriginal('password'));
        $this->assertNull($admin->getRawOriginal('remember_token'));
    }

    public function test_without_password_refuses_a_password_in_the_environment_too(): void
    {
        putenv('OPENPNE_ADMIN_PASSWORD=strong-pass-1');

        try {
            $this->artisan('openpne:admin:create', ['username' => 'new', '--without-password' => true])->assertFailed();
        } finally {
            putenv('OPENPNE_ADMIN_PASSWORD');
        }

        $this->assertDatabaseCount('admin_users', 0);
    }

    public function test_rejects_a_weak_password(): void
    {
        $this->artisan('openpne:admin:create', ['username' => 'weak'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertDatabaseMissing('admin_users', ['username' => 'weak']);
    }
}
