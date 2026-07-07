<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The member TOTP login challenge (Fortify two-factor feature, confirm mode): a confirmed
 * second factor turns the password login into a redirect to /two-factor-challenge, completed
 * by a TOTP code or a single-use recovery code.
 */
class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithTwoFactor(bool $confirmed = true): Member
    {
        $member = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        if ($confirmed) {
            $member->forceFill(['two_factor_confirmed_at' => now()])->save();
        }

        return $member->fresh();
    }

    private function currentOtp(Member $member): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($member->two_factor_secret));
    }

    /** POST /login for $member and assert it parked the login at the challenge. */
    private function startChallenge(Member $member, bool $remember = false): void
    {
        $this->post('/login', array_filter([
            'email' => $member->email,
            'password' => 'password',
            'remember' => $remember ? 'on' : null,
        ]))->assertRedirect('/two-factor-challenge');

        $this->assertGuest();
        $this->assertSame($member->getKey(), session('login.id'));
    }

    public function test_login_with_a_confirmed_second_factor_redirects_to_the_challenge(): void
    {
        $this->startChallenge($this->memberWithTwoFactor());
    }

    public function test_a_pending_unconfirmed_secret_does_not_gate_login(): void
    {
        // Confirm mode's lockout guarantee: starting set-up and walking away changes nothing.
        $member = $this->memberWithTwoFactor(confirmed: false);

        $this->post('/login', ['email' => $member->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($member);
    }

    public function test_the_challenge_renders_the_classic_surface_by_default(): void
    {
        $this->startChallenge($this->memberWithTwoFactor());

        $this->get('/two-factor-challenge')
            ->assertOk()
            ->assertSee('id="twoFactorChallenge"', false)
            ->assertSee('name="code"', false)
            ->assertSee('name="recovery_code"', false);
    }

    public function test_the_challenge_renders_the_modern_surface_when_selected(): void
    {
        config()->set('openpne.surface_mode', 'modern_default');
        $this->startChallenge($this->memberWithTwoFactor());

        $this->get('/two-factor-challenge')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/two-factor-challenge'));
    }

    public function test_a_valid_totp_code_completes_the_login(): void
    {
        $member = $this->memberWithTwoFactor();
        $this->startChallenge($member);
        $sessionIdBeforeChallenge = session()->getId();

        $response = $this->post('/two-factor-challenge', ['code' => $this->currentOtp($member)]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($member);
        $this->assertNull(session('login.id'));
        // Completing the challenge is the authentication moment, so it must be fixation-safe.
        $this->assertNotSame($sessionIdBeforeChallenge, session()->getId());
    }

    public function test_an_invalid_code_is_rejected_and_can_be_retried(): void
    {
        $member = $this->memberWithTwoFactor();
        $this->startChallenge($member);

        $this->post('/two-factor-challenge', ['code' => 'not-a-code'])
            ->assertRedirect('/two-factor-challenge')
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        // login.id survives a failed attempt, so the member can retry without re-entering the password.
        $this->post('/two-factor-challenge', ['code' => $this->currentOtp($member)]);
        $this->assertAuthenticatedAs($member);
    }

    public function test_a_recovery_code_completes_the_login_and_is_consumed(): void
    {
        // Deleted, not swapped for a fresh one (Member::replaceRecoveryCode): codes are shown
        // exactly once at set-up, so the member could never learn a silent replacement — the
        // unused count must shrink as their saved codes are spent.
        $member = $this->memberWithTwoFactor();
        $codes = $member->recoveryCodes();
        $this->startChallenge($member);

        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]]);

        $this->assertAuthenticatedAs($member);
        $remaining = $member->fresh()->recoveryCodes();
        $this->assertNotContains($codes[0], $remaining);
        $this->assertCount(7, $remaining);
    }

    public function test_an_invalid_recovery_code_is_rejected(): void
    {
        $this->startChallenge($this->memberWithTwoFactor());

        $this->post('/two-factor-challenge', ['recovery_code' => 'wrong-code'])
            ->assertRedirect('/two-factor-challenge')
            ->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_remember_me_survives_the_challenge(): void
    {
        // The recaller is only minted here, after the full password + TOTP login — the basis for
        // keeping remember-me available to members (docs/internals/security.md).
        $member = $this->memberWithTwoFactor();
        $this->startChallenge($member, remember: true);

        $this->post('/two-factor-challenge', ['code' => $this->currentOtp($member)])
            ->assertCookie(Auth::guard('member')->getRecallerName());
    }

    public function test_the_challenge_post_is_throttled_but_the_get_render_is_not(): void
    {
        $member = $this->memberWithTwoFactor();
        $this->startChallenge($member);

        foreach (range(1, 5) as $i) {
            $this->post('/two-factor-challenge', ['code' => 'not-a-code'])->assertRedirect();
        }
        $this->post('/two-factor-challenge', ['code' => 'not-a-code'])->assertStatus(429);

        // The render must stay reachable while throttled: refreshing the form is not a guess.
        $this->get('/two-factor-challenge')->assertOk();
    }

    public function test_direct_access_without_a_challenged_member_bounces(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect('/login');
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
    }
}
