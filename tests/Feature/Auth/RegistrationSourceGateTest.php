<?php

namespace Tests\Feature\Auth;

use App\Features\Auth\RegistrationTokenSource;
use App\Models\RegistrationToken;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The completion half is gated by the token's origin against the current mode, not by the open-entry
 * gate: an invite must complete in invite mode, while a self token must not. closed switches the
 * whole completion route off (any token, even an unknown one) before the lookup.
 */
class RegistrationSourceGateTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'sufficiently-long-pw';

    /** @return array<string, string> */
    private function validForm(): array
    {
        return ['name' => 'Newcomer', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    private function issue(string $email, RegistrationTokenSource $source): string
    {
        $raw = Str::random(40);
        RegistrationToken::create([
            'email' => $email,
            'token' => hash('sha256', $raw),
            'source' => $source,
            'created_at' => now(),
        ]);

        return $raw;
    }

    public function test_a_member_invite_token_completes_in_invite_mode(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $raw = $this->issue('invitee@example.com', RegistrationTokenSource::MemberInvite);

        $this->get("/register/{$raw}")->assertOk();
        $this->post("/register/{$raw}", $this->validForm())->assertRedirect('/');
        $this->assertDatabaseHas('members', ['email' => 'invitee@example.com']);
    }

    public function test_a_self_token_404s_in_invite_mode(): void
    {
        // Switching out of open mode retroactively blocks an outstanding self-service link.
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $raw = $this->issue('self@example.com', RegistrationTokenSource::Selfservice);

        $this->get("/register/{$raw}")->assertNotFound();
        $this->post("/register/{$raw}", $this->validForm())->assertNotFound();
        $this->assertDatabaseCount('members', 0);
    }

    public function test_a_self_token_completes_in_open_mode(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'open');
        $raw = $this->issue('self@example.com', RegistrationTokenSource::Selfservice);

        $this->post("/register/{$raw}", $this->validForm())->assertRedirect('/');
        $this->assertDatabaseHas('members', ['email' => 'self@example.com']);
    }

    public function test_closed_mode_404s_a_valid_token(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'closed');
        $raw = $this->issue('invitee@example.com', RegistrationTokenSource::MemberInvite);

        $this->get("/register/{$raw}")->assertNotFound();
        $this->post("/register/{$raw}", $this->validForm())->assertNotFound();
    }

    public function test_closed_mode_404s_an_unknown_token_too(): void
    {
        // The closed check runs before the token lookup, so the whole route is off — a known and an
        // unknown token are indistinguishable (no redirect that would leak the difference).
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'closed');

        $this->get('/register/'.Str::random(40))->assertNotFound();
    }

    public function test_an_unknown_token_in_invite_mode_redirects_to_login_not_404(): void
    {
        // The open entry is gone in invite mode, so an unknown/expired link sends the visitor to sign
        // in (a fresh link comes from a new invitation), never to the 404'd /register.
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');

        $this->get('/register/'.Str::random(40))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
    }
}
