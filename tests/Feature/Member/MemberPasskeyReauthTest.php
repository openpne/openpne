<?php

namespace Tests\Feature\Member;

use App\Auth\Reauth\PasskeyProof;
use App\Auth\Reauth\ReauthProof;
use App\Auth\Reauth\ReauthPurpose;
use App\Features\Member\Actions\RegenerateMemberRecoveryCodes;
use App\Features\Member\ReauthPasskeyController;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CapturesSecurityLog;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredential;

class MemberPasskeyReauthTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->captureSecurityLog();
    }

    private function registeredPasskey(Member $member): FakeAuthenticator
    {
        $authenticator = FakeAuthenticator::forApp();
        $options = app(GenerateRegistrationOptions::class)($member);
        $credential = WebAuthn::fromJson(json_encode($authenticator->attest(WebAuthn::toBrowserArray($options))), PublicKeyCredential::class);
        app(StorePasskey::class)($member, 'phone', $credential, $options);

        return $authenticator;
    }

    private function memberWithTwoFactor(): Member
    {
        $member = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $member->fresh();
    }

    /** @return array<string, mixed> */
    private function ceremonyOptions(string $purpose): array
    {
        return $this->getJson('/member/reauth/passkey/options?purpose='.$purpose)->assertOk()->json('options');
    }

    private function prove(Member $member, ReauthPurpose $purpose): void
    {
        $this->actingAs($member, 'member')->withSession([]);
        PasskeyProof::issue(app('session.store'), $member, $purpose);
    }

    public function test_the_ceremony_issues_a_proof_for_the_purpose_it_was_started_for(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);
        $this->actingAs($member, 'member');

        $this->postJson('/member/reauth/passkey', ['credential' => $authenticator->assert($this->ceremonyOptions('withdrawal')), 'purpose' => 'password-change'])
            ->assertOk()
            ->assertJsonPath('purpose', 'withdrawal');

        $this->assertTrue(PasskeyProof::stands(app('session.store'), $member, ReauthPurpose::Withdrawal));
        $this->assertFalse(PasskeyProof::stands(app('session.store'), $member, ReauthPurpose::PasswordChange));
        $this->assertNotNull(Passkey::where('credential_id', $authenticator->credentialId())->value('last_used_at'));

        $logged = $this->assertOneSecurityEvent('passkey.verified');
        $this->assertSame('reauth', $logged['via']);
        $this->assertSame('withdrawal', $logged['purpose']);
    }

    public function test_a_challenge_verifies_once(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);
        $this->actingAs($member, 'member');
        $options = $this->ceremonyOptions('withdrawal');

        $this->postJson('/member/reauth/passkey', ['credential' => $authenticator->assert($options)])->assertOk();
        $this->postJson('/member/reauth/passkey', ['credential' => $authenticator->assert($options)])
            ->assertUnprocessable()->assertJsonValidationErrors('credential');
    }

    /** A parallel request that began earlier saves its stale session over the pulled slot. */
    public function test_a_challenge_verifies_once_even_when_the_session_is_rewound(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);
        $this->actingAs($member, 'member');
        $options = $this->ceremonyOptions('withdrawal');
        $slot = session(ReauthPasskeyController::OPTIONS_SLOT);

        $this->postJson('/member/reauth/passkey', ['credential' => $authenticator->assert($options)])->assertOk();

        $this->withSession([ReauthPasskeyController::OPTIONS_SLOT => $slot, PasskeyProof::SLOT => null]);
        $this->postJson('/member/reauth/passkey', ['credential' => $authenticator->assert($options)])
            ->assertUnprocessable()->assertJsonValidationErrors('credential');
        $this->assertFalse(PasskeyProof::stands(app('session.store'), $member, ReauthPurpose::Withdrawal));
    }

    public function test_another_members_passkey_is_refused(): void
    {
        $member = Member::factory()->create();
        $this->registeredPasskey($member);
        $theirs = $this->registeredPasskey(Member::factory()->create());
        $this->actingAs($member, 'member');

        $this->postJson('/member/reauth/passkey', ['credential' => $theirs->assert($this->ceremonyOptions('withdrawal'))])
            ->assertUnprocessable();

        $this->assertFalse(PasskeyProof::stands(app('session.store'), $member, ReauthPurpose::Withdrawal));
        $failed = $this->assertOneSecurityEvent('passkey.failed');
        $this->assertSame('reauth', $failed['via']);
    }

    public function test_an_assertion_without_user_verification_is_refused(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);
        $authenticator->userVerified = false;
        $this->actingAs($member, 'member');

        $this->postJson('/member/reauth/passkey', ['credential' => $authenticator->assert($this->ceremonyOptions('withdrawal'))])
            ->assertUnprocessable();

        $this->assertFalse(PasskeyProof::stands(app('session.store'), $member, ReauthPurpose::Withdrawal));
    }

    public function test_the_login_challenge_is_a_different_slot(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);

        // A challenge minted for the login screen, as a guest, then carried into the signed-in session.
        $login = $this->getJson('/passkeys/login/options')->assertOk()->json('options');
        $this->actingAs($member, 'member');

        $this->postJson('/member/reauth/passkey', ['credential' => $authenticator->assert($login)])
            ->assertUnprocessable()->assertJsonValidationErrors('credential');

        $this->ceremonyOptions('withdrawal');
        $this->assertNotNull(session('passkey.verification_options'));
        $this->assertNotNull(session(ReauthPasskeyController::OPTIONS_SLOT));
    }

    public function test_options_are_refused_for_an_unknown_purpose_or_a_member_without_a_passkey(): void
    {
        $member = Member::factory()->create();
        $this->actingAs($member, 'member');

        $this->getJson('/member/reauth/passkey/options?purpose=withdrawal')->assertNotFound();

        $this->registeredPasskey($member);
        $this->getJson('/member/reauth/passkey/options?purpose=nonsense')->assertNotFound();
        $this->getJson('/member/reauth/passkey/options')->assertNotFound();
    }

    public function test_a_proof_stands_in_for_the_password(): void
    {
        $member = Member::factory()->create();
        $this->prove($member, ReauthPurpose::PasswordChange);

        $this->post('/member/config/password', ['password' => 'new-secret-pass', 'password_confirmation' => 'new-secret-pass'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue(Hash::check('new-secret-pass', $member->fresh()->password));
        $this->assertNull(session(PasskeyProof::SLOT));
    }

    /** The Modern forms post JSON with the password field always present, empty when unused. */
    public function test_an_empty_password_in_a_json_body_is_no_password(): void
    {
        $member = Member::factory()->create();
        $this->prove($member, ReauthPurpose::EmailChange);

        $this->postJson('/member/config/email', ['new_email' => 'first@example.com', 'current_password' => ''])->assertRedirect();
        $this->assertNull(session(PasskeyProof::SLOT));

        $this->postJson('/member/config/email', ['new_email' => 'second@example.com', 'current_password' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['current_password' => __('validation.required', ['attribute' => __('validation.attributes.current_password')])]);
    }

    public function test_a_proof_for_another_purpose_or_an_old_one_is_refused(): void
    {
        // Withdrawal refuses member id 1.
        Member::factory()->create();
        $member = Member::factory()->create();

        $this->prove($member, ReauthPurpose::EmailChange);
        $this->post('/member/config/withdrawal', ['confirm' => '1'])->assertSessionHasErrors('current_password');

        $this->prove($member, ReauthPurpose::Withdrawal);
        $this->travel(121)->seconds();
        $this->post('/member/config/withdrawal', ['confirm' => '1'])->assertSessionHasErrors('current_password');

        $this->assertNotNull($member->fresh());
    }

    public function test_a_proof_made_for_another_member_is_refused(): void
    {
        $member = Member::factory()->create();
        $other = Member::factory()->create();
        $this->prove($other, ReauthPurpose::PasswordChange);
        $this->actingAs($member, 'member');

        $this->post('/member/config/password', ['password' => 'new-secret-pass', 'password_confirmation' => 'new-secret-pass'])
            ->assertSessionHasErrors('current_password');
    }

    /** A second request read the same session payload: replaying the slot stands in for the parallel read. */
    public function test_a_proof_is_spent_by_the_first_request_even_when_the_session_is_rewound(): void
    {
        $member = Member::factory()->create();
        $this->prove($member, ReauthPurpose::EmailChange);
        $issued = session(PasskeyProof::SLOT);

        $this->post('/member/config/email', ['new_email' => 'first@example.com'])->assertSessionHasNoErrors();

        $this->withSession([PasskeyProof::SLOT => $issued]);
        $this->post('/member/config/email', ['new_email' => 'second@example.com'])->assertSessionHasErrors('current_password');
    }

    public function test_a_password_that_was_sent_is_verified_and_leaves_the_proof_alone(): void
    {
        $member = Member::factory()->create();
        $this->prove($member, ReauthPurpose::EmailChange);

        $this->post('/member/config/email', ['new_email' => 'first@example.com', 'current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');
        $this->post('/member/config/email', ['new_email' => 'first@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(PasskeyProof::stands(app('session.store'), $member, ReauthPurpose::EmailChange));
    }

    public function test_a_proof_opens_a_window_like_a_password_does(): void
    {
        $member = Member::factory()->create();
        $this->registeredPasskey($member);
        $this->prove($member, ReauthPurpose::PasskeyAdd);

        $this->post('/member/config/passkeys/reauth')->assertSessionHasNoErrors();
        $this->getJson('/member/config/passkeys/options')->assertOk();
    }

    public function test_a_proof_removes_a_live_factor_without_a_code(): void
    {
        $member = $this->memberWithTwoFactor();
        $this->prove($member, ReauthPurpose::MfaDisable);

        $this->post('/member/config/mfa/disable')->assertSessionHasNoErrors();

        $this->assertNull($member->fresh()->two_factor_secret);
    }

    public function test_new_recovery_codes_still_take_a_totp_code_beside_the_proof(): void
    {
        $member = $this->memberWithTwoFactor();
        $before = $member->two_factor_recovery_codes;

        $this->prove($member, ReauthPurpose::MfaRecoveryCodes);
        $this->post('/member/config/mfa/recovery-codes')->assertSessionHasErrors('code');
        $this->assertTrue(PasskeyProof::stands(app('session.store'), $member, ReauthPurpose::MfaRecoveryCodes));

        $this->post('/member/config/mfa/recovery-codes', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertSame($before, $member->fresh()->two_factor_recovery_codes);

        $this->prove($member, ReauthPurpose::MfaRecoveryCodes);
        $this->post('/member/config/mfa/recovery-codes', ['code' => app(Google2FA::class)->getCurrentOtp(decrypt($member->two_factor_secret))])
            ->assertSessionHasNoErrors();
        $this->assertNotSame($before, $member->fresh()->two_factor_recovery_codes);
    }

    /** The request saw no factor and asked for no code; one confirmed since must not get codes without it. */
    public function test_new_recovery_codes_fail_closed_when_a_factor_went_live_after_the_passkey_request_was_validated(): void
    {
        $member = $this->memberWithTwoFactor();
        $before = $member->two_factor_recovery_codes;

        try {
            app(RegenerateMemberRecoveryCodes::class)($member, ReauthProof::passkey(false, null));
            $this->fail('The proof was meant to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_password', $e->errors());
        }

        $this->assertSame($before, $member->fresh()->two_factor_recovery_codes);
    }

    public function test_the_ceremony_routes_are_throttled_per_member(): void
    {
        $member = Member::factory()->create();
        $this->registeredPasskey($member);
        $this->actingAs($member, 'member');

        // The challenge GET is exempt, since a cancelled browser prompt refetches it.
        for ($i = 0; $i < 12; $i++) {
            $this->ceremonyOptions('withdrawal');
        }

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/member/reauth/passkey', [])->assertUnprocessable();
        }

        $this->postJson('/member/reauth/passkey', [])->assertTooManyRequests();
    }
}
