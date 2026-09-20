<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Tests\Concerns\CapturesSecurityLog;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredential;

class PasskeyLoginTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureSecurityLog();
    }

    /** Registers through the package's actions, the way the settings page ends up storing one. */
    private function registeredPasskey(Member $member): FakeAuthenticator
    {
        $authenticator = FakeAuthenticator::forApp();
        $options = app(GenerateRegistrationOptions::class)($member);
        $credential = WebAuthn::fromJson(json_encode($authenticator->attest(WebAuthn::toBrowserArray($options))), PublicKeyCredential::class);
        app(StorePasskey::class)($member, 'phone', $credential, $options);

        return $authenticator;
    }

    /** @return array<string, mixed> */
    private function loginOptions(): array
    {
        return $this->getJson('/passkeys/login/options')->assertOk()->json('options');
    }

    public function test_a_registered_passkey_signs_the_member_in_without_a_username(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);

        $sessionBefore = app('session.store')->getId();
        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions())])
            ->assertOk()
            ->assertJsonPath('redirect', url('/'));

        $this->assertAuthenticatedAs($member);
        $this->assertNotSame($sessionBefore, app('session.store')->getId());
        $this->assertNotNull(Passkey::where('credential_id', $authenticator->credentialId())->value('last_used_at'));
        $this->assertSame((string) $member->getKey(), $this->assertOneSecurityEvent('login.success')['member_id']);
        $this->assertSame((string) $member->getKey(), $this->assertOneSecurityEvent('passkey.verified')['member_id']);
    }

    public function test_remember_mints_a_recaller(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);

        $response = $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions()), 'remember' => true])
            ->assertOk();

        $this->assertNotNull($response->getCookie(Auth::guard('member')->getRecallerName(), false));
    }

    public function test_a_member_with_a_confirmed_totp_factor_lands_without_the_challenge(): void
    {
        $member = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();
        $authenticator = $this->registeredPasskey($member->fresh());

        // A password login left the TOTP challenge pending in this very session.
        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertSame($member->getKey(), session('login.id'));

        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions())])
            ->assertOk()
            ->assertJsonPath('redirect', url('/'));

        $this->assertAuthenticatedAs($member);
        $this->assertNull(session('login.id'));
    }

    public function test_the_challenge_is_single_use(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);
        $options = $this->loginOptions();

        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($options)])->assertOk();
        $this->post('/logout');

        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($options)])
            ->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_an_assertion_without_user_verification_is_refused(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);
        $authenticator->userVerified = false;

        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions())])
            ->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_a_counter_that_went_backwards_is_refused(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);

        $authenticator->counter = 5;
        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions())])->assertOk();
        $this->post('/logout');

        $authenticator->counter = 3;
        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions())])
            ->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_an_unknown_credential_is_refused(): void
    {
        $this->registeredPasskey(Member::factory()->create());
        $stranger = FakeAuthenticator::forApp();

        $this->postJson('/passkeys/login', ['credential' => $stranger->assert($this->loginOptions())])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');
        $this->assertGuest();
    }

    public function test_a_banned_member_is_refused_after_a_valid_assertion(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => true]);
        $authenticator = $this->registeredPasskey($member);

        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions())])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');

        $this->assertGuest();
        // The assertion was verified before the gate: the audit row and last_used_at both move.
        $this->assertOneSecurityEvent('passkey.verified');
        $this->assertSame((string) $member->getKey(), $this->assertOneSecurityEvent('passkey.refused')['member_id']);
        $this->assertSame([], $this->securityRecords('login.success'));
        $this->assertNotNull(Passkey::where('credential_id', $authenticator->credentialId())->value('last_used_at'));
    }

    public function test_an_ai_account_row_is_refused_even_with_a_passkey(): void
    {
        $owner = Member::factory()->create();
        $ai = Member::factory()->aiAccount($owner)->create();
        $authenticator = FakeAuthenticator::forApp();
        $options = app(GenerateRegistrationOptions::class)($ai);
        // Bypasses the app's registration refusal to prove the login gate holds on its own.
        $ai->passkeys()->create([
            'name' => 'ai',
            'credential_id' => $authenticator->credentialId(),
            'credential' => json_decode(WebAuthn::toJson(
                WebAuthn::attestationValidator()->check(
                    WebAuthn::fromJson(json_encode($authenticator->attest(WebAuthn::toBrowserArray($options))), PublicKeyCredential::class)->response,
                    $options,
                    parse_url(config('app.url'), PHP_URL_HOST),
                ),
            ), true),
        ]);

        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions())])
            ->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_the_login_post_is_throttled_per_ip_but_the_options_get_is_not(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);

        foreach (range(1, 10) as $i) {
            $options = $this->loginOptions();
            $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($options)])->assertOk();
            $this->post('/logout');
        }

        $options = $this->loginOptions();
        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($options)])->assertStatus(429);
        $this->getJson('/passkeys/login/options')->assertOk();
    }

    public function test_the_login_pages_offer_the_passkey_path_on_both_surfaces(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('data-passkey-login', false)
            ->assertSee(route('passkey.login-options'), false);

        config(['openpne.surface_mode' => 'modern_default']);
        $this->get('/login')->assertInertia(fn ($page) => $page->component('auth/login'));
    }

    public function test_a_signed_in_member_cannot_start_another_passkey_login(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->getJson('/passkeys/login/options')->assertRedirect('/');
    }
}
