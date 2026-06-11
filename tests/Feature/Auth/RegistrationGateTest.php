<?php

namespace Tests\Feature\Auth;

use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The registration-mode gate (OpenPNE 3 invite_mode parity): the open /register entry exists only in
 * 'open' mode; 'invite' (the default) and 'closed' 404 it, and the login page hides the register link
 * unless the entry is reachable.
 */
class RegistrationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_mode_404s_the_open_entry(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');

        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'newcomer@example.com'])->assertNotFound();
        $this->get('/register/sent')->assertNotFound();
    }

    public function test_closed_mode_404s_the_open_entry(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'closed');

        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'newcomer@example.com'])->assertNotFound();
    }

    public function test_an_unknown_mode_falls_back_to_invite_and_404s(): void
    {
        // A typo must never accidentally expose the open entry.
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'nonsense');

        $this->get('/register')->assertNotFound();
    }

    public function test_open_mode_exposes_the_entry(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'open');

        $this->get('/register')->assertOk()->assertSee('name="email"', false);
    }

    public function test_the_classic_login_shows_the_register_link_only_when_open(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $this->get('/login')->assertOk()->assertDontSee('registerLink', false);

        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'open');
        $this->get('/login')->assertOk()->assertSee('registerLink', false);
    }

    public function test_the_modern_login_passes_registration_open_to_the_page(): void
    {
        config()->set('openpne.tenant_default_surface', 'modern');

        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $this->get('/login')->assertInertia(
            fn (AssertableInertia $page) => $page->component('auth/login')->where('registrationOpen', false)
        );

        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'open');
        $this->get('/login')->assertInertia(
            fn (AssertableInertia $page) => $page->component('auth/login')->where('registrationOpen', true)
        );
    }
}
