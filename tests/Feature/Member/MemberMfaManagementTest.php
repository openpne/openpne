<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Member two-factor management: one password re-auth per flow (enable opens a window that
 * covers confirm; disabling a live factor and regenerating codes re-auth every time; cancelling
 * a pending set-up never does). Confirm and live-factor disable revoke the member's other
 * sessions (a factor change is a credential change); regenerating codes and cancelling a
 * pending set-up revoke nothing. Recovery codes render exactly once.
 */
class MemberMfaManagementTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPendingSetup(): Member
    {
        $member = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($member, force: true);

        return $member->fresh();
    }

    private function memberWithTwoFactor(): Member
    {
        $member = $this->memberWithPendingSetup();
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $member->fresh();
    }

    private function currentOtp(Member $member): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($member->two_factor_secret));
    }

    private function insertOtherDeviceSession(Member $member): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'other-device-session', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);
    }

    public function test_a_wrong_password_is_rejected_on_every_management_post(): void
    {
        // Whenever the field is provided it must be the account password, whatever the action's
        // required-ness (window fresh or not, factor live or not).
        $member = Member::factory()->create();

        foreach (['enable', 'confirm', 'disable', 'recovery-codes'] as $action) {
            $this->actingAs($member)
                ->post("/member/config/mfa/{$action}", ['current_password' => 'wrong-password', 'code' => '000000'])
                ->assertSessionHasErrors('current_password');
        }

        $this->assertNull($member->fresh()->two_factor_secret);
    }

    public function test_enable_writes_a_pending_secret_and_recovery_codes(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post('/member/config/mfa/enable', ['current_password' => 'password'])
            ->assertRedirect('/member/config?category=mfa');

        $fresh = $member->fresh();
        $this->assertNotNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertCount(8, $fresh->recoveryCodes());
    }

    public function test_enable_is_rejected_once_the_factor_is_confirmed(): void
    {
        // Rotating the live secret under a set two_factor_confirmed_at would be an instant
        // lockout at the next challenge.
        $member = $this->memberWithTwoFactor();
        $secret = $member->two_factor_secret;

        $this->actingAs($member)
            ->post('/member/config/mfa/enable', ['current_password' => 'password'])
            ->assertForbidden();

        $this->assertSame($secret, $member->fresh()->two_factor_secret);
    }

    public function test_enable_is_rejected_while_a_setup_is_pending(): void
    {
        // Enable never rotates an existing secret in place: a parallel enable racing a confirm
        // could otherwise stamp two_factor_confirmed_at against a secret the member never
        // scanned. Restarting is cancel (disable) first, then enable.
        $member = $this->memberWithPendingSetup();
        $secret = $member->two_factor_secret;

        $this->actingAs($member)
            ->post('/member/config/mfa/enable', ['current_password' => 'password'])
            ->assertForbidden();

        $this->assertSame($secret, $member->fresh()->two_factor_secret);
    }

    public function test_classic_routes_the_password_error_to_the_form_that_was_submitted(): void
    {
        // The enabled page has two password forms sharing the error key; the flashed _mfa_form
        // marker routes the error into the submitted (collapsed) disable form and reopens it.
        // No assertSessionHasErrors between the POST and the GET: reading the session in the
        // assertion ages the flash, which would blank the very render under test.
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)
            ->from('/member/config?category=mfa')
            ->post('/member/config/mfa/disable', ['current_password' => 'wrong-password', '_mfa_form' => 'disable'])
            ->assertRedirect('/member/config?category=mfa');

        $this->get('/member/config?category=mfa')
            ->assertSee('<details open>', false)
            ->assertSeeInOrder(['id="mfa_disable_password"', 'The password is incorrect.'], false);

        // A wrong password on the regenerate form (the default) leaves the disable details closed.
        $this->post('/member/config/mfa/recovery-codes', ['current_password' => 'wrong-password', '_mfa_form' => 'regenerate']);
        $this->get('/member/config?category=mfa')
            ->assertDontSee('<details open>', false)
            ->assertSeeInOrder(['id="mfa_regen_password"', 'The password is incorrect.'], false);
    }

    public function test_confirm_within_the_reauth_window_needs_no_password(): void
    {
        // The password was verified moments ago at enable; asking again in the same sitting is
        // the double-entry the sudo-mode window exists to avoid.
        $member = Member::factory()->create();
        $this->actingAs($member)->post('/member/config/mfa/enable', ['current_password' => 'password']);

        $fresh = $member->fresh();
        $this->post('/member/config/mfa/confirm', ['code' => $this->currentOtp($fresh)]);

        $this->assertNotNull($fresh->fresh()->two_factor_confirmed_at);
    }

    public function test_an_empty_password_field_does_not_shadow_the_code_error(): void
    {
        // Shared client form state submits current_password as '' even when the window hides the
        // field; that must read as "not provided", or the invalid-code error would be displaced
        // by a password error the page has no field to show.
        $member = Member::factory()->create();
        $this->actingAs($member)->post('/member/config/mfa/enable', ['current_password' => 'password']);

        $this->post('/member/config/mfa/confirm', ['code' => 'not-a-code', 'current_password' => ''])
            ->assertSessionHasErrors('code')
            ->assertSessionDoesntHaveErrors('current_password');
    }

    public function test_confirm_after_the_window_lapses_demands_the_password(): void
    {
        $member = Member::factory()->create();
        $this->actingAs($member)->post('/member/config/mfa/enable', ['current_password' => 'password']);
        $fresh = $member->fresh();

        $this->travel(16)->minutes();

        // Codes stay valid under Carbon travel (google2fa reads the real clock); only the window lapses.
        $this->post('/member/config/mfa/confirm', ['code' => $this->currentOtp($fresh)])
            ->assertSessionHasErrors('current_password');
        $this->assertNull($fresh->fresh()->two_factor_confirmed_at);

        $this->post('/member/config/mfa/confirm', ['code' => $this->currentOtp($fresh), 'current_password' => 'password']);
        $this->assertNotNull($fresh->fresh()->two_factor_confirmed_at);
    }

    public function test_disabling_a_live_factor_without_the_password_is_rejected(): void
    {
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)
            ->post('/member/config/mfa/disable')
            ->assertSessionHasErrors('current_password');

        $this->assertNotNull($member->fresh()->two_factor_secret);
    }

    public function test_the_classic_category_renders_each_state(): void
    {
        $member = Member::factory()->create();

        // Disabled: the set-up form.
        $this->actingAs($member)->get('/member/config?category=mfa')
            ->assertOk()
            ->assertSee('id="member_config_mfa"', false)
            ->assertSee(route('member.config.mfa.enable'), false);

        // Pending: QR data URI, setup key, confirm form.
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $this->actingAs($member->fresh())->get('/member/config?category=mfa')
            ->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee('name="code"', false)
            ->assertSee(route('member.config.mfa.confirm'), false);

        // Enabled: regenerate + disable controls, count instead of codes (nothing was just minted).
        $member->fresh()->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($member->fresh())->get('/member/config?category=mfa')
            ->assertOk()
            ->assertSee(route('member.config.mfa.recovery'), false)
            ->assertSee(route('member.config.mfa.disable'), false)
            ->assertDontSee('data:image/svg+xml;base64,', false);
    }

    public function test_the_modern_detail_page_renders_each_state(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/member/config/mfa')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('member/config/mfa')
                ->where('state', 'disabled')
                ->missing('secret'));

        app(EnableTwoFactorAuthentication::class)($member, force: true);
        // Enabled outside the HTTP flow, so no re-auth stamp: the page must ask for the password.
        $this->actingAs($member->fresh())->get('/m/member/config/mfa')
            ->assertInertia(fn (Assert $page) => $page
                ->component('member/config/mfa')
                ->where('state', 'pending')
                ->where('requiresPassword', true)
                ->has('qrCode')
                ->has('secret'));

        $member->fresh()->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($member->fresh())->get('/m/member/config/mfa')
            ->assertInertia(fn (Assert $page) => $page
                ->component('member/config/mfa')
                ->where('state', 'enabled')
                ->where('recoveryCodesCount', 8)
                ->missing('recoveryCodes')
                ->missing('secret'));
    }

    public function test_the_settings_hub_shows_only_the_enabled_flag(): void
    {
        $member = $this->memberWithTwoFactor();

        $response = $this->actingAs($member)->get('/m/member/config');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('member/config')
            ->where('form.mfa.enabled', true));
        $this->assertStringNotContainsString('two_factor_secret', $response->getContent());
    }

    public function test_confirm_turns_the_factor_on_and_revokes_other_sessions(): void
    {
        $member = $this->memberWithPendingSetup();
        $member->forceFill(['remember_token' => 'old-remember-token'])->save();
        $this->insertOtherDeviceSession($member);

        $response = $this->actingAs($member)->post('/member/config/mfa/confirm', [
            'current_password' => 'password',
            'code' => $this->currentOtp($member),
        ]);

        $response->assertRedirect('/member/config?category=mfa');
        $fresh = $member->fresh();
        $this->assertNotNull($fresh->two_factor_confirmed_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        $this->assertNotSame('old-remember-token', $fresh->remember_token);

        // The fresh recovery codes render exactly once (flash), then fall back to the count.
        $this->get('/member/config?category=mfa')->assertSee($fresh->recoveryCodes()[0], false);
        $this->get('/member/config?category=mfa')->assertDontSee($fresh->recoveryCodes()[0], false);
    }

    public function test_confirm_with_an_invalid_code_stays_pending(): void
    {
        $member = $this->memberWithPendingSetup();
        $this->insertOtherDeviceSession($member);

        $this->actingAs($member)
            ->post('/member/config/mfa/confirm', ['current_password' => 'password', 'code' => 'not-a-code'])
            ->assertSessionHasErrors('code');

        // Default bag (not Fortify's named bag), still pending, and nothing was revoked.
        $this->assertNull($member->fresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('sessions', ['id' => 'other-device-session']);
    }

    public function test_confirm_is_rejected_without_a_pending_setup(): void
    {
        $disabled = Member::factory()->create();
        $this->actingAs($disabled)
            ->post('/member/config/mfa/confirm', ['current_password' => 'password', 'code' => '000000'])
            ->assertForbidden();

        $enabled = $this->memberWithTwoFactor();
        $this->actingAs($enabled)
            ->post('/member/config/mfa/confirm', ['current_password' => 'password', 'code' => $this->currentOtp($enabled)])
            ->assertForbidden();
    }

    public function test_disable_clears_the_factor_and_revokes_other_sessions(): void
    {
        $member = $this->memberWithTwoFactor();
        $member->forceFill(['remember_token' => 'old-remember-token'])->save();
        $this->insertOtherDeviceSession($member);

        $this->actingAs($member)
            ->post('/member/config/mfa/disable', ['current_password' => 'password'])
            ->assertRedirect('/member/config?category=mfa');

        $fresh = $member->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        $this->assertNotSame('old-remember-token', $fresh->remember_token);
    }

    public function test_disable_with_nothing_set_up_revokes_nothing(): void
    {
        $member = Member::factory()->create(['remember_token' => 'old-remember-token']);
        $this->insertOtherDeviceSession($member);

        $this->actingAs($member)
            ->post('/member/config/mfa/disable', ['current_password' => 'password'])
            ->assertRedirect('/member/config?category=mfa');

        $this->assertDatabaseHas('sessions', ['id' => 'other-device-session']);
        $this->assertSame('old-remember-token', $member->fresh()->remember_token);
    }

    public function test_cancelling_a_pending_setup_clears_the_secret_without_a_password(): void
    {
        // The pending secret gates nothing, so abandoning the wizard costs nothing to undo — and
        // since the cancel is password-free it must also be side-effect-free: no session purge,
        // no remember_token rotation a walked-up session could trigger for free.
        $member = $this->memberWithPendingSetup();
        $member->forceFill(['remember_token' => 'old-remember-token'])->save();
        $this->insertOtherDeviceSession($member);

        $this->actingAs($member)->post('/member/config/mfa/disable');

        $fresh = $member->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertDatabaseHas('sessions', ['id' => 'other-device-session']);
        $this->assertSame('old-remember-token', $fresh->remember_token);
    }

    public function test_regenerate_rotates_codes_without_revoking_sessions(): void
    {
        $member = $this->memberWithTwoFactor();
        $member->forceFill(['remember_token' => 'old-remember-token'])->save();
        $this->insertOtherDeviceSession($member);
        $oldCodes = $member->recoveryCodes();

        $this->actingAs($member)
            ->post('/member/config/mfa/recovery-codes', ['current_password' => 'password'])
            ->assertRedirect('/member/config?category=mfa');

        $fresh = $member->fresh();
        $this->assertNotEquals($oldCodes, $fresh->recoveryCodes());
        $this->assertCount(8, $fresh->recoveryCodes());
        // The TOTP factor is unchanged, so nothing is revoked (admin parity).
        $this->assertDatabaseHas('sessions', ['id' => 'other-device-session']);
        $this->assertSame('old-remember-token', $fresh->remember_token);
    }

    public function test_regenerate_requires_a_confirmed_factor(): void
    {
        $member = $this->memberWithPendingSetup();

        $this->actingAs($member)
            ->post('/member/config/mfa/recovery-codes', ['current_password' => 'password'])
            ->assertForbidden();
    }

    public function test_modern_posts_redirect_to_the_modern_detail_page(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post('/m/member/config/mfa/enable', ['current_password' => 'password'])
            ->assertRedirect('/m/member/config/mfa');
    }

    public function test_management_requires_authentication(): void
    {
        $this->get('/m/member/config/mfa')->assertRedirect('/login');
        $this->post('/member/config/mfa/enable', ['current_password' => 'password'])->assertRedirect('/login');
    }

    public function test_the_secret_never_serializes_from_the_model(): void
    {
        $member = $this->memberWithTwoFactor();

        $this->assertArrayNotHasKey('two_factor_secret', $member->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $member->toArray());
    }
}
