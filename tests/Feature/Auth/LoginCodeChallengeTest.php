<?php

namespace Tests\Feature\Auth;

use App\Auth\RequireSecondLoginProof;
use App\Features\Auth\LoginCode;
use App\Models\Member;
use App\Notifications\Auth\LoginCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Support\WebAuthn;
use Tests\Concerns\CapturesSecurityLog;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredential;

class LoginCodeChallengeTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->captureSecurityLog();
    }

    private function memberWithPasskey(?FakeAuthenticator $authenticator = null): Member
    {
        $member = Member::factory()->create();
        $authenticator ??= FakeAuthenticator::forApp();
        $options = app(GenerateRegistrationOptions::class)($member);
        $credential = WebAuthn::fromJson(json_encode($authenticator->attest(WebAuthn::toBrowserArray($options))), PublicKeyCredential::class);
        app(StorePasskey::class)($member, 'phone', $credential, $options);

        return $member->fresh();
    }

    private function startChallenge(Member $member, bool $remember = false): void
    {
        $this->post('/login', array_filter([
            'email' => $member->email,
            'password' => 'password',
            'remember' => $remember ? 'on' : null,
        ]))->assertRedirect('/login/code');

        $this->assertGuest();
    }

    /** The code of the most recent mail. */
    private function mailedCode(): string
    {
        $code = null;
        Notification::assertSentOnDemand(LoginCodeNotification::class, function (LoginCodeNotification $n) use (&$code): bool {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    private function wrongCode(): string
    {
        return $this->mailedCode() === '000000' ? '000001' : '000000';
    }

    public function test_a_member_without_a_passkey_signs_in_on_the_password_alone(): void
    {
        $member = Member::factory()->create();

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect('/');

        $this->assertAuthenticatedAs($member);
        Notification::assertNothingSent();
    }

    public function test_a_passkey_holder_is_parked_at_the_code_challenge_and_mailed_once(): void
    {
        $member = $this->memberWithPasskey();

        $this->startChallenge($member);

        $this->assertNull(session('login.id'));
        $this->assertSame($member->getKey(), session('login.code.member_id'));
        Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 1);
        Notification::assertSentOnDemand(
            LoginCodeNotification::class,
            fn ($n, array $channels, $notifiable): bool => $channels === ['mail'] && $notifiable->routes['mail'] === $member->email,
        );
        $this->assertSame((string) $member->getKey(), $this->assertOneSecurityEvent('login.code_sent')['member_id']);
    }

    public function test_the_session_never_holds_the_code_itself(): void
    {
        $this->startChallenge($this->memberWithPasskey());

        $this->assertStringNotContainsString($this->mailedCode(), json_encode(session()->all()));
    }

    public function test_a_confirmed_totp_factor_takes_the_totp_challenge_and_mails_nothing(): void
    {
        $member = $this->memberWithPasskey();
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');

        $this->assertNull(session('login.code'));
        Notification::assertNothingSent();
    }

    public function test_the_right_code_completes_the_login(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $sessionIdBefore = session()->getId();

        $this->post('/login/code', ['code' => $this->mailedCode()])->assertRedirect('/');

        $this->assertAuthenticatedAs($member);
        $this->assertNull(session('login.code'));
        $this->assertNotSame($sessionIdBefore, session()->getId());
        $this->assertSame((string) $member->getKey(), $this->assertOneSecurityEvent('login.success')['member_id']);
    }

    public function test_no_recaller_is_minted_after_the_code(): void
    {
        $member = $this->memberWithPasskey();
        $recaller = Auth::guard('member')->getRecallerName();

        $this->post('/login', ['email' => $member->email, 'password' => 'password', 'remember' => 'on'])
            ->assertCookieMissing($recaller);

        $this->post('/login/code', ['code' => $this->mailedCode()])->assertCookieMissing($recaller);
    }

    public function test_a_wrong_code_is_rejected_logged_and_can_be_retried(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);

        $this->from('/login/code')->post('/login/code', ['code' => $this->wrongCode()])
            ->assertRedirect('/login/code')
            ->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame((string) $member->getKey(), $this->assertOneSecurityEvent('login.code_failed')['member_id']);

        $this->post('/login/code', ['code' => $this->mailedCode()]);
        $this->assertAuthenticatedAs($member);
    }

    public function test_the_fifth_wrong_code_ends_the_challenge(): void
    {
        $this->startChallenge($this->memberWithPasskey());
        $code = $this->mailedCode();

        foreach (range(1, 4) as $i) {
            $this->post('/login/code', ['code' => $this->wrongCode()]);
            $this->assertNotNull(session('login.code'));
        }
        $this->post('/login/code', ['code' => $this->wrongCode()])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertNull(session('login.code'));
        $this->travel(2)->minutes();
        $this->post('/login/code', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();
    }

    /** The attempt count lives outside the session, so replaying the session as issued does not reset it. */
    public function test_rewinding_the_session_does_not_buy_more_guesses(): void
    {
        $this->startChallenge($this->memberWithPasskey());
        $code = $this->mailedCode();
        $slot = session('login.code');

        foreach (range(1, 5) as $i) {
            $this->post('/login/code', ['code' => $this->wrongCode()]);
        }
        $this->travel(2)->minutes();

        $this->withSession(['login.code' => $slot])->post('/login/code', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->startChallenge($this->memberWithPasskey());
        $code = $this->mailedCode();

        $this->travel(LoginCode::TTL_MINUTES + 1)->minutes();

        $this->get('/login/code')->assertRedirect('/login');
        $this->post('/login/code', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_direct_access_without_a_challenge_bounces_to_login(): void
    {
        $this->get('/login/code')->assertRedirect('/login');
        $this->post('/login/code', ['code' => '000000'])->assertRedirect('/login');
        $this->post('/login/code/resend')->assertRedirect('/login');
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_neither_challenge_accepts_the_others_slot(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $code = $this->mailedCode();

        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();

        $this->flushSession();
        $this->withSession(['login.id' => $member->getKey()])->post('/login/code', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_resend_replaces_the_code_and_the_old_one_stops_working(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $old = $this->mailedCode();
        $oldSlot = session('login.code');

        $this->from('/login/code')->post('/login/code/resend')->assertRedirect('/login/code')->assertSessionHas('status');

        Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 2);
        $this->assertNotSame($oldSlot['nonce'], session('login.code.nonce'));
        $this->assertNotSame($oldSlot['hash'], session('login.code.hash'));
        if ($old !== $this->mailedCode()) {
            $this->post('/login/code', ['code' => $old]);
            $this->assertGuest();
        }

        $this->post('/login/code', ['code' => $this->mailedCode()]);
        $this->assertAuthenticatedAs($member);
    }

    public function test_the_sixth_code_in_an_hour_is_not_sent(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        foreach (range(1, 4) as $i) {
            $this->post('/login/code/resend');
        }

        $this->from('/login/code')->post('/login/code/resend')->assertSessionHasErrors('code');
        Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 5);

        $this->flushSession();
        $this->from('/login')->post('/login', ['email' => $member->email, 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 5);
    }

    public function test_the_code_post_is_throttled_but_the_render_is_not(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $slot = session('login.code');

        foreach (range(1, 5) as $i) {
            $this->withSession(['login.code' => $slot])->post('/login/code', ['code' => $this->wrongCode()])->assertRedirect();
        }
        $this->withSession(['login.code' => $slot])->post('/login/code', ['code' => $this->wrongCode()])->assertStatus(429);

        $this->withSession(['login.code' => $slot])->get('/login/code')->assertOk();
    }

    public function test_a_passkey_sign_in_clears_a_pending_code_challenge(): void
    {
        $authenticator = FakeAuthenticator::forApp();
        $member = $this->memberWithPasskey($authenticator);
        $this->startChallenge($member);

        $options = $this->getJson('/passkeys/login/options')->json('options');
        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($options)])->assertOk();

        $this->assertAuthenticatedAs($member);
        $this->assertNull(session('login.code'));
    }

    public function test_a_member_banned_during_the_challenge_does_not_get_in(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $member->forceFill(['is_login_rejected' => true])->save();

        $this->post('/login/code', ['code' => $this->mailedCode()])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_password_change_during_the_challenge_voids_the_code(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $member->forceFill(['password' => Hash::make('a-new-password')])->save();

        $this->post('/login/code', ['code' => $this->mailedCode()])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_resend_does_not_revive_a_challenge_the_password_change_voided(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $member->forceFill(['password' => Hash::make('a-new-password')])->save();

        $this->post('/login/code/resend')->assertRedirect('/login');

        $this->assertNull(session('login.code'));
        Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 1);
    }

    public function test_a_resend_mails_nothing_to_a_member_banned_during_the_challenge(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $member->forceFill(['is_login_rejected' => true])->save();

        $this->post('/login/code/resend')->assertRedirect('/login');

        Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 1);
    }

    public function test_a_totp_factor_confirmed_during_the_challenge_ends_it(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $code = $this->mailedCode();
        $slot = session('login.code');
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->post('/login/code', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();

        $this->withSession(['login.code' => $slot])->post('/login/code/resend')->assertRedirect('/login');
        Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 1);
    }

    public function test_an_address_change_during_the_challenge_voids_the_code_and_the_resend(): void
    {
        $member = $this->memberWithPasskey();
        $this->startChallenge($member);
        $code = $this->mailedCode();
        $slot = session('login.code');
        $member->forceFill(['email' => 'moved@example.test'])->save();

        $this->post('/login/code', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();

        $this->withSession(['login.code' => $slot])->post('/login/code/resend')->assertRedirect('/login');
        Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 1);
    }

    public function test_the_challenge_routes_are_serialised_per_session(): void
    {
        foreach (['login.code', 'login.code.store', 'login.code.resend'] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name)->locksFor(), "route [{$name}] lost its session block");
        }
    }

    public function test_starting_either_challenge_drops_the_other_ones_slot(): void
    {
        $totpMember = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($totpMember, force: true);
        $totpMember->forceFill(['two_factor_confirmed_at' => now()])->save();
        $passkeyMember = $this->memberWithPasskey();

        $this->post('/login', ['email' => $totpMember->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->startChallenge($passkeyMember);
        $this->assertNull(session('login.id'));

        $this->post('/login', ['email' => $totpMember->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertNull(session('login.code'));
    }

    public function test_the_login_pipeline_resolves_the_passkey_rule(): void
    {
        $this->assertTrue(Features::enabled(Features::twoFactorAuthentication()));
        $this->assertInstanceOf(RequireSecondLoginProof::class, app(RedirectsIfTwoFactorAuthenticatable::class));
    }

    public function test_the_challenge_renders_on_both_surfaces(): void
    {
        $this->startChallenge($this->memberWithPasskey());
        $slot = session('login.code');

        $this->get('/login/code')
            ->assertOk()
            ->assertSee('id="loginCode"', false)
            ->assertSee('autocomplete="one-time-code"', false);

        config()->set('openpne.surface_mode', 'modern_default');
        $this->withSession(['login.code' => $slot])->get('/login/code')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/login-code'));
    }
}
