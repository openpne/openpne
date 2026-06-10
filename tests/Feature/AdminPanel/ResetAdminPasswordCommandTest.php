<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_fails_for_an_unknown_username(): void
    {
        $this->artisan('openpne:admin:reset-password', ['username' => 'ghost'])
            ->assertFailed();
    }
}
