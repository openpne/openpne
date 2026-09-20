<?php

namespace Tests\Feature\Member;

use App\Features\Member\Actions\DeleteMemberPasskey;
use App\Features\Member\Actions\OpenPasskeyReauth;
use App\Features\Member\Actions\RegisterMemberPasskey;
use App\Features\Member\PasskeyReauth;
use App\Models\Member;
use App\Notifications\Member\PasskeyRegisteredNotification;
use App\Notifications\Member\PasskeyRemovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CapturesSecurityLog;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

class MemberPasskeyManagementTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureSecurityLog();
        Notification::fake();
    }

    private function memberWithTwoFactor(): Member
    {
        $member = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $member->fresh();
    }

    private function currentOtp(Member $member): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($member->two_factor_secret));
    }

    private function reauth(Member $member): void
    {
        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'password'])
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function registrationOptions(Member $member): array
    {
        return $this->actingAs($member)->getJson('/member/config/passkeys/options')->assertOk()->json('options');
    }

    private function register(Member $member, ?FakeAuthenticator $authenticator = null, string $name = 'My phone'): Passkey
    {
        $authenticator ??= FakeAuthenticator::forApp();
        $this->reauth($member);
        $credential = $authenticator->attest($this->registrationOptions($member));

        $this->actingAs($member)
            ->postJson('/member/config/passkeys', ['name' => $name, 'credential' => $credential])
            ->assertOk();

        return Passkey::where('credential_id', $authenticator->credentialId())->firstOrFail();
    }

    private function insertOtherDeviceSession(Member $member): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'other-device-session', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);
    }

    public function test_a_registration_round_trip_stores_the_credential_under_user_id(): void
    {
        $member = Member::factory()->create();
        $authenticator = FakeAuthenticator::forApp();

        $passkey = $this->register($member, $authenticator);

        $this->assertSame($member->getKey(), $passkey->user_id);
        $this->assertSame('My phone', $passkey->name);
        $this->assertTrue($passkey->credential['backupEligible']);
        $this->assertTrue($passkey->credential['backupStatus']);
        // The relation is what every reader goes through; the vendor trait alone would query member_id.
        $this->assertTrue($member->passkeys()->whereKey($passkey->getKey())->exists());
        Notification::assertSentTo($member, PasskeyRegisteredNotification::class);
        $event = $this->assertOneSecurityEvent('passkey.registered');
        $this->assertSame((string) $member->getKey(), $event['member_id']);
        $this->assertSame((string) $passkey->getKey(), $event['passkey_id']);
    }

    public function test_options_and_store_need_the_reauth_window(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->getJson('/member/config/passkeys/options')->assertForbidden();
        $this->actingAs($member)
            ->postJson('/member/config/passkeys', ['name' => 'x', 'credential' => ['id' => 'a', 'rawId' => 'a', 'type' => 'public-key', 'response' => []]])
            ->assertForbidden();
        $this->assertSame(0, Passkey::count());
    }

    public function test_a_wrong_password_never_opens_the_window(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');

        $this->assertFalse(PasskeyReauth::isFresh(app('session.store')));
        $this->actingAs($member)->getJson('/member/config/passkeys/options')->assertForbidden();
    }

    public function test_a_confirmed_factor_demands_a_second_factor_to_open_the_window(): void
    {
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'password'])
            ->assertSessionHasErrors('code');
        $this->assertFalse(PasskeyReauth::isFresh(app('session.store')));

        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'password', 'code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertFalse(PasskeyReauth::isFresh(app('session.store')));

        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'password', 'code' => $this->currentOtp($member)])
            ->assertSessionHasNoErrors();
        $this->assertTrue(PasskeyReauth::isFresh(app('session.store')));
    }

    public function test_a_recovery_code_opens_the_window_and_is_consumed(): void
    {
        $member = $this->memberWithTwoFactor();
        $code = $member->recoveryCodes()[0];

        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'password', 'recovery_code' => $code])
            ->assertSessionHasNoErrors();

        $this->assertTrue(PasskeyReauth::isFresh(app('session.store')));
        $this->assertNotContains($code, $member->fresh()->recoveryCodes());
        $this->assertSame((string) $member->getKey(), $this->assertOneSecurityEvent('mfa.recovery_code_used')['member_id']);
    }

    public function test_a_wrong_password_never_spends_a_recovery_code_nor_burns_a_totp_code(): void
    {
        $member = $this->memberWithTwoFactor();
        $code = $member->recoveryCodes()[0];
        $otp = $this->currentOtp($member);

        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'wrong-password', 'recovery_code' => $code])
            ->assertSessionHasErrors('current_password');
        $this->assertContains($code, $member->fresh()->recoveryCodes());

        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'wrong-password', 'code' => $otp])
            ->assertSessionHasErrors('current_password');

        // The same code still opens the window: it was never marked used.
        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'password', 'code' => $otp])
            ->assertSessionHasNoErrors();
        $this->assertTrue(PasskeyReauth::isFresh(app('session.store')));
    }

    public function test_a_reauth_racing_a_factor_change_fails_closed(): void
    {
        $member = Member::factory()->create();

        // Validated as "no factor" on the session's instance, then the factor goes live in the row
        // before the Action re-reads it.
        $this->app->bind(OpenPasskeyReauth::class, function ($app) use ($member) {
            $row = $member->fresh();
            app(EnableTwoFactorAuthentication::class)($row, force: true);
            $row->forceFill(['two_factor_confirmed_at' => now()])->save();

            return new OpenPasskeyReauth($app->make(TwoFactorAuthenticationProvider::class));
        });

        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'password'])
            ->assertSessionHasErrors('current_password');
        $this->assertFalse(PasskeyReauth::isFresh(app('session.store')));
    }

    public function test_a_successful_registration_spends_the_window(): void
    {
        $member = Member::factory()->create();
        $this->register($member);

        $this->assertFalse(PasskeyReauth::isFresh(app('session.store')));
        $this->actingAs($member)->getJson('/member/config/passkeys/options')->assertForbidden();
    }

    public function test_a_cancelled_ceremony_keeps_the_window_and_a_stale_challenge_is_rejected(): void
    {
        $member = Member::factory()->create();
        $authenticator = FakeAuthenticator::forApp();
        $this->reauth($member);

        $first = $this->registrationOptions($member);
        // The browser prompt was dismissed; the page fetches fresh options for the retry.
        $second = $this->registrationOptions($member);
        $this->assertTrue(PasskeyReauth::isFresh(app('session.store')));

        $this->actingAs($member)
            ->postJson('/member/config/passkeys', ['name' => 'stale', 'credential' => $authenticator->attest($first)])
            ->assertUnprocessable();
        $this->assertSame(0, Passkey::count());

        // The challenge was pulled by the failed attempt, so even the fresh one needs a new fetch.
        $this->actingAs($member)
            ->postJson('/member/config/passkeys', ['name' => 'fresh', 'credential' => $authenticator->attest($second)])
            ->assertUnprocessable();
    }

    public function test_the_window_lapses_after_fifteen_minutes(): void
    {
        $member = Member::factory()->create();
        $this->reauth($member);

        $this->travel(15)->minutes();
        $this->actingAs($member)->getJson('/member/config/passkeys/options')->assertOk();

        $this->travel(1)->seconds();
        $this->actingAs($member)->getJson('/member/config/passkeys/options')->assertForbidden();
    }

    public function test_an_attestation_without_user_verification_is_refused(): void
    {
        $member = Member::factory()->create();
        $authenticator = FakeAuthenticator::forApp();
        $authenticator->userVerified = false;
        $this->reauth($member);

        $this->actingAs($member)
            ->postJson('/member/config/passkeys', ['name' => 'no-uv', 'credential' => $authenticator->attest($this->registrationOptions($member))])
            ->assertUnprocessable();
        $this->assertSame(0, Passkey::count());
    }

    public function test_an_attestation_from_another_origin_is_refused(): void
    {
        $member = Member::factory()->create();
        $authenticator = new FakeAuthenticator('https://evil.example');
        $this->reauth($member);

        $this->actingAs($member)
            ->postJson('/member/config/passkeys', ['name' => 'phish', 'credential' => $authenticator->attest($this->registrationOptions($member))])
            ->assertUnprocessable();
        $this->assertSame(0, Passkey::count());
    }

    public function test_a_credential_id_longer_than_the_column_is_refused_at_validation(): void
    {
        $member = Member::factory()->create();
        $authenticator = FakeAuthenticator::forApp(credentialIdBytes: 400);
        $this->reauth($member);

        $this->actingAs($member)
            ->postJson('/member/config/passkeys', ['name' => 'long', 'credential' => $authenticator->attest($this->registrationOptions($member))])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential.rawId');
    }

    public function test_a_lapsed_window_answers_with_the_translated_reason(): void
    {
        $member = Member::factory()->create();
        $this->reauth($member);
        $this->travel(16)->minutes();

        $reason = __('Some time has passed since you confirmed your password. Please confirm it again.');
        $this->actingAs($member)->getJson('/member/config/passkeys/options')->assertForbidden()->assertJsonPath('message', $reason);
        $this->actingAs($member)
            ->postJson('/member/config/passkeys', ['name' => 'x', 'credential' => ['id' => 'a', 'rawId' => 'a', 'type' => 'public-key', 'response' => []]])
            ->assertForbidden()->assertJsonPath('message', $reason);
    }

    public function test_the_same_credential_cannot_be_registered_twice(): void
    {
        $authenticator = FakeAuthenticator::forApp();
        $this->register(Member::factory()->create(), $authenticator);

        $other = Member::factory()->create();
        $this->reauth($other);
        $this->actingAs($other)
            ->postJson('/member/config/passkeys', ['name' => 'dup', 'credential' => $authenticator->attest($this->registrationOptions($other))])
            ->assertUnprocessable();
        $this->assertSame(1, Passkey::count());
    }

    public function test_credential_ids_differing_only_in_case_are_distinct(): void
    {
        $member = Member::factory()->create();
        $member->passkeys()->create(['name' => 'a', 'credential_id' => 'AbC', 'credential' => []]);
        $member->passkeys()->create(['name' => 'b', 'credential_id' => 'abc', 'credential' => []]);

        $this->assertSame(1, Passkey::where('credential_id', 'AbC')->count());
        $this->assertSame(1, Passkey::where('credential_id', 'abc')->count());
    }

    public function test_the_name_is_capped_at_255_characters(): void
    {
        $member = Member::factory()->create();
        $this->reauth($member);

        $this->actingAs($member)
            ->postJson('/member/config/passkeys', ['name' => str_repeat('x', 256), 'credential' => FakeAuthenticator::forApp()->attest($this->registrationOptions($member))])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_an_ai_account_row_never_gains_a_passkey(): void
    {
        $owner = Member::factory()->create();
        $ai = Member::factory()->aiAccount($owner)->create();
        $authenticator = FakeAuthenticator::forApp();

        // No session can belong to an AI account; the Action refuses even if one did.
        $this->reauth($owner);
        $options = $this->registrationOptions($owner);
        $this->expectException(HttpException::class);
        app(RegisterMemberPasskey::class)(
            $ai,
            'ai',
            WebAuthn::fromJson(json_encode($authenticator->attest($options)), PublicKeyCredential::class),
            WebAuthn::fromJson(session('passkey.registration_options'), PublicKeyCredentialCreationOptions::class),
        );
    }

    public function test_deleting_needs_the_password_and_revokes_other_sessions(): void
    {
        $member = Member::factory()->create();
        $passkey = $this->register($member);

        $this->actingAs($member)
            ->delete("/member/config/passkeys/{$passkey->getKey()}", ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame(1, Passkey::count());

        $this->insertOtherDeviceSession($member);
        $before = $member->fresh()->remember_token;

        $this->actingAs($member)
            ->delete("/member/config/passkeys/{$passkey->getKey()}", ['current_password' => 'password'])
            ->assertRedirect('/member/config?category=passkey');

        $this->assertSame(0, Passkey::count());
        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device-session')->count());
        $this->assertNotSame($before, $member->fresh()->remember_token);
        Notification::assertSentTo($member, PasskeyRemovedNotification::class);
        $this->assertSame((string) $passkey->getKey(), $this->assertOneSecurityEvent('passkey.removed')['passkey_id']);
    }

    public function test_deleting_keeps_the_session_that_asked(): void
    {
        $member = Member::factory()->create();
        $passkey = $this->register($member);
        config(['session.driver' => 'database']);
        foreach (['this-device', 'other-device'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $member->getKey(), 'payload' => base64_encode('{}'), 'last_activity' => time()]);
        }

        app(DeleteMemberPasskey::class)($member, $passkey->getKey(), 'this-device');

        $this->assertSame(['this-device'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_deleting_someone_elses_or_a_missing_passkey_is_a_uniform_404(): void
    {
        $owner = Member::factory()->create();
        $passkey = $this->register($owner);
        $intruder = Member::factory()->create();

        $this->actingAs($intruder)
            ->delete("/member/config/passkeys/{$passkey->getKey()}", ['current_password' => 'password'])
            ->assertNotFound();
        $this->actingAs($intruder)
            ->delete('/member/config/passkeys/999999', ['current_password' => 'password'])
            ->assertNotFound();
        $this->assertSame(1, Passkey::count());
        $this->assertSame([], $this->securityRecords('passkey.removed'));
    }

    public function test_a_double_delete_revokes_logs_and_mails_once(): void
    {
        $member = Member::factory()->create();
        $passkey = $this->register($member);

        $this->actingAs($member)->delete("/member/config/passkeys/{$passkey->getKey()}", ['current_password' => 'password'])->assertRedirect();
        $this->actingAs($member)->delete("/member/config/passkeys/{$passkey->getKey()}", ['current_password' => 'password'])->assertNotFound();

        $this->assertCount(1, $this->securityRecords('passkey.removed'));
        Notification::assertSentToTimes($member, PasskeyRemovedNotification::class, 1);
    }

    public function test_adding_a_passkey_revokes_nothing(): void
    {
        $member = Member::factory()->create();
        $this->insertOtherDeviceSession($member);
        $before = $member->fresh()->remember_token;

        $this->register($member);

        $this->assertSame(1, DB::table('sessions')->where('id', 'other-device-session')->count());
        $this->assertSame($before, $member->fresh()->remember_token);
    }

    public function test_the_mutating_routes_share_one_budget_that_fits_a_registration(): void
    {
        $member = Member::factory()->create();
        $authenticator = FakeAuthenticator::forApp();

        // reauth + store = 2 of 5; the options GET is exempt.
        $this->register($member, $authenticator);
        foreach (range(1, 3) as $i) {
            $this->actingAs($member)
                ->post('/member/config/passkeys/reauth', ['current_password' => 'wrong-password'])
                ->assertSessionHasErrors('current_password');
        }
        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'wrong-password'])
            ->assertStatus(429);

        $this->actingAs($member)->get('/member/config/passkeys')->assertOk();
    }

    public function test_the_modern_detail_page_and_hub_render_the_list_without_credential_material(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();
        $passkey = $this->register($member);

        $response = $this->actingAs($member)->get('/member/config/passkeys');
        $response->assertInertia(fn (Assert $page) => $page
            ->component('member/config/passkeys')
            ->has('passkeys', 1)
            ->where('passkeys.0.id', $passkey->getKey())
            ->where('passkeys.0.name', 'My phone')
            ->where('passkeys.0.synced', true)
            ->where('requiresPassword', true)
            ->where('requiresSecondFactor', false)
            ->where('deviceBoundOnly', false));
        $this->assertStringNotContainsString($passkey->credential_id, $response->getContent());
        $this->assertStringNotContainsString('publicKey', $response->getContent());

        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn (Assert $page) => $page->component('member/config')->where('form.passkeys.count', 1));
    }

    public function test_a_member_holding_only_device_bound_passkeys_is_flagged(): void
    {
        $member = Member::factory()->create();
        $authenticator = FakeAuthenticator::forApp();
        $authenticator->backupEligible = false;
        $authenticator->backedUp = false;
        $this->register($member, $authenticator);

        $this->actingAs($member)->get('/member/config/passkeys')
            ->assertInertia(fn (Assert $page) => $page->where('passkeys.0.synced', false)->where('deviceBoundOnly', true));
    }

    public function test_the_classic_category_renders_the_list_and_the_forms(): void
    {
        $member = Member::factory()->create();
        $passkey = $this->register($member);

        $this->actingAs($member)->get('/member/config?category=passkey')
            ->assertOk()
            ->assertSee('id="member_config_passkeys"', false)
            ->assertSee('My phone')
            ->assertSee(route('member.config.passkeys.reauth'), false)
            ->assertSee(route('member.config.passkeys.destroy', ['id' => $passkey->getKey()]), false)
            ->assertDontSee($passkey->credential_id, false);
    }

    public function test_the_classic_category_offers_the_register_button_only_inside_the_window(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=passkey')
            ->assertDontSee('data-passkey-register', false);

        $this->reauth($member);
        $this->actingAs($member)->get('/member/config?category=passkey')
            ->assertSee('data-passkey-register', false)
            ->assertSee(route('member.config.passkeys.options'), false);
    }

    public function test_management_requires_authentication(): void
    {
        $this->get('/member/config/passkeys')->assertRedirect('/login');
        $this->getJson('/member/config/passkeys/options')->assertUnauthorized();
        $this->post('/member/config/passkeys/reauth', ['current_password' => 'password'])->assertRedirect('/login');
    }
}
