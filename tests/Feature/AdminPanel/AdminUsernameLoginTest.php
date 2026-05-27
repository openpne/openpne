<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Filament\Pages\Auth\Login;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Administrators authenticate by `username` (OpenPNE 3 has no administrator
 * email). Exercising the Filament login Livewire component runs the real
 * authenticate() path including Login::getCredentialsFromFormData() — without
 * which a regression to `'email' => $data['email']` would silently break the
 * form while leaving guard-level credential tests green.
 */
class AdminUsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_admin_login_page_presents_a_username_field(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Username');
    }

    public function test_administrator_can_authenticate_through_the_login_form(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'opene']);

        // The form field key is `email` (Filament internal) but holds the
        // username; Login::getCredentialsFromFormData() rekeys it to `username`.
        Livewire::test(Login::class)
            ->fillForm(['email' => 'opene', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_administrator_cannot_authenticate_with_a_wrong_password(): void
    {
        AdminUser::factory()->create(['username' => 'opene']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'opene', 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors();

        $this->assertGuest('admin');
    }
}
