<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Filament\Pages\Auth\Login;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const ATTEMPTS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_the_attempt_past_the_limit_is_refused_with_the_right_password_too(): void
    {
        AdminUser::factory()->create(['username' => 'opene']);

        foreach (range(1, self::ATTEMPTS) as $attempt) {
            Livewire::test(Login::class)
                ->fillForm(['email' => 'opene', 'password' => 'wrong-password'])
                ->call('authenticate')
                ->assertHasFormErrors();
        }

        Livewire::test(Login::class)
            ->fillForm(['email' => 'opene', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertGuest('admin');
    }

    public function test_the_last_attempt_inside_the_limit_still_signs_in(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'opene']);

        foreach (range(1, self::ATTEMPTS - 1) as $attempt) {
            Livewire::test(Login::class)
                ->fillForm(['email' => 'opene', 'password' => 'wrong-password'])
                ->call('authenticate')
                ->assertHasFormErrors();
        }

        Livewire::test(Login::class)
            ->fillForm(['email' => 'opene', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_another_username_from_the_same_address_is_refused_as_well(): void
    {
        AdminUser::factory()->create(['username' => 'opene']);
        AdminUser::factory()->create(['username' => 'other']);

        foreach (range(1, self::ATTEMPTS) as $attempt) {
            Livewire::test(Login::class)
                ->fillForm(['email' => 'opene', 'password' => 'wrong-password'])
                ->call('authenticate');
        }

        Livewire::test(Login::class)
            ->fillForm(['email' => 'other', 'password' => 'password'])
            ->call('authenticate')
            ->assertNotified();

        $this->assertGuest('admin');
    }

    public function test_another_address_is_counted_on_its_own(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'opene']);

        foreach (range(1, self::ATTEMPTS) as $attempt) {
            Livewire::test(Login::class)
                ->fillForm(['email' => 'opene', 'password' => 'wrong-password'])
                ->call('authenticate');
        }

        // The component's requests are built by Livewire's own harness, which takes no address.
        $this->app->rebinding('request', fn ($app, $request) => $request->server->set('REMOTE_ADDR', '203.0.113.9'));
        Livewire::test(Login::class)
            ->fillForm(['email' => 'opene', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin, 'admin');
    }
}
