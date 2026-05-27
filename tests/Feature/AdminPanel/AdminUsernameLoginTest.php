<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Administrators authenticate by `username` (OpenPNE 3 has no administrator
 * email). The Filament login page presents a username field and the `admin`
 * guard resolves credentials against the `admin_user.username` column.
 */
class AdminUsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_page_presents_a_username_field(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Username');
    }

    public function test_admin_guard_authenticates_by_username(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'opene']);

        $this->assertTrue(
            Auth::guard('admin')->attempt(['username' => 'opene', 'password' => 'password'])
        );
        $this->assertSame($admin->id, Auth::guard('admin')->id());
    }

    public function test_admin_guard_rejects_invalid_password(): void
    {
        AdminUser::factory()->create(['username' => 'opene']);

        $this->assertFalse(
            Auth::guard('admin')->attempt(['username' => 'opene', 'password' => 'wrong'])
        );
        $this->assertNull(Auth::guard('admin')->id());
    }
}
