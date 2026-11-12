<?php

namespace Tests\Feature\Member;

use App\Auth\Reauth\PasskeyProof;
use App\Auth\Reauth\ReauthCodeBudget;
use App\Auth\Reauth\ReauthPurpose;
use App\Models\Member;
use App\Notifications\Member\MfaCodesPausedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

/** The route limiters are switched off throughout, so what refuses here is the budget alone. */
class MemberReauthCodeBudgetTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    private const URI = '/member/config/passkeys/reauth';

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->captureSecurityLog();
        $this->withoutMiddleware(ThrottleRequests::class);

        $member = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->member = $member->fresh();
        $this->actingAs($this->member, 'member');
        $this->useSession(str_repeat('a', 40));
    }

    /** Without the cookie every test request starts a session under a new id. */
    private function useSession(string $id): void
    {
        $this->withCookie((string) config('session.cookie'), $id);
    }

    private function failures(): int
    {
        return (int) Cache::get('reauth-code-failures|'.$this->member->getKey(), 0);
    }

    private function otp(): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($this->member->two_factor_secret));
    }

    private function wrongCodes(int $count, int $secondsApart = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->travel($secondsApart)->seconds();
            $this->post(self::URI, ['current_password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
        }
    }

    public function test_a_sixth_code_within_a_minute_is_not_compared(): void
    {
        $this->wrongCodes(5);

        $this->post(self::URI, ['current_password' => 'password', 'code' => $this->otp()])
            ->assertSessionHasErrors(['code' => __('Too many authentication codes were tried. Please wait a minute and try again.')]);

        // A code the minute budget refused was never compared, so it is no step towards the pause.
        $this->assertSame(5, $this->failures());

        $this->travel(61)->seconds();
        $this->post(self::URI, ['current_password' => 'password', 'code' => $this->otp()])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->failures());
    }

    /** The database store shares the default connection, so a count taken inside the verifier's transaction would roll back with it. */
    public function test_a_wrong_code_is_counted_on_the_database_cache_store(): void
    {
        config(['cache.default' => 'database']);

        $this->wrongCodes(3);

        $this->assertSame(3, $this->failures());
    }

    public function test_a_right_code_does_not_reset_the_count_when_the_operation_rolls_back(): void
    {
        $this->wrongCodes(2);
        $this->mock(GenerateNewRecoveryCodes::class)->shouldReceive('__invoke')->andThrow(new RuntimeException('boom'));

        $this->withoutExceptionHandling();
        try {
            $this->post('/member/config/mfa/recovery-codes', ['current_password' => 'password', 'code' => $this->otp()]);
            $this->fail('The operation was meant to throw.');
        } catch (RuntimeException) {
        }

        $this->assertSame(3, $this->failures());
    }

    public function test_the_minute_budget_is_per_session(): void
    {
        $this->wrongCodes(5);

        $this->useSession(str_repeat('b', 40));
        $this->post(self::URI, ['current_password' => 'password', 'code' => $this->otp()])->assertSessionHasNoErrors();
    }

    public function test_every_step_up_draws_on_the_same_budget(): void
    {
        $this->wrongCodes(3);
        $this->post('/member/config/mfa/recovery-codes', ['current_password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/member/config/email', ['new_email' => 'new@example.com', 'current_password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');

        $this->post('/member/config/mfa/disable', ['current_password' => 'password', 'code' => $this->otp()])
            ->assertSessionHasErrors(['code' => __('Too many authentication codes were tried. Please wait a minute and try again.')]);
        $this->assertNotNull($this->member->fresh()->two_factor_secret);
    }

    public function test_a_wrong_password_or_a_recovery_code_spends_nothing(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->post(self::URI, ['current_password' => 'wrong-password', 'code' => '000000'])->assertSessionHasErrors('current_password');
            $this->post(self::URI, ['current_password' => 'password', 'recovery_code' => 'not-a-code'])->assertSessionHasErrors('recovery_code');
        }

        $this->post(self::URI, ['current_password' => 'password', 'code' => $this->otp()])->assertSessionHasNoErrors();
    }

    public function test_codes_are_paused_after_the_consecutive_limit_and_the_member_is_told_once(): void
    {
        $this->wrongCodes(ReauthCodeBudget::MAX_CONSECUTIVE, secondsApart: 13);
        Notification::assertNothingSent();

        $paused = __('Authentication codes are paused for your account after too many wrong ones. Use a recovery code or a passkey, or try again tomorrow.');
        $this->travel(61)->seconds();
        $this->post(self::URI, ['current_password' => 'password', 'code' => $this->otp()])->assertSessionHasErrors(['code' => $paused]);
        $this->travel(61)->seconds();
        $this->post(self::URI, ['current_password' => 'password', 'code' => $this->otp()])->assertSessionHasErrors(['code' => $paused]);

        Notification::assertSentToTimes($this->member, MfaCodesPausedNotification::class, 1);
        $this->assertSame((string) $this->member->getKey(), (string) $this->assertOneSecurityEvent('mfa.codes_paused')['member_id']);
    }

    public function test_a_recovery_code_and_a_passkey_still_work_while_codes_are_paused(): void
    {
        $this->wrongCodes(ReauthCodeBudget::MAX_CONSECUTIVE + 1, secondsApart: 13);

        $this->post('/member/config/email', ['new_email' => 'new@example.com', 'current_password' => 'password', 'recovery_code' => $this->member->recoveryCodes()[0]])
            ->assertSessionHasNoErrors();

        PasskeyProof::issue(app('session.store'), $this->member, ReauthPurpose::MfaDisable);
        $this->post('/member/config/mfa/disable')->assertSessionHasNoErrors();
        $this->assertNull($this->member->fresh()->two_factor_secret);
    }

    public function test_a_passkey_proof_survives_a_refusal_by_the_budget(): void
    {
        $this->wrongCodes(5);
        PasskeyProof::issue(app('session.store'), $this->member, ReauthPurpose::MfaRecoveryCodes);

        $this->post('/member/config/mfa/recovery-codes', ['code' => $this->otp()])->assertSessionHasErrors('code');

        $this->assertTrue(PasskeyProof::stands(app('session.store'), $this->member, ReauthPurpose::MfaRecoveryCodes));
    }

    public function test_the_pause_lifts_a_day_after_the_first_wrong_code(): void
    {
        $this->wrongCodes(ReauthCodeBudget::MAX_CONSECUTIVE + 1, secondsApart: 13);

        $this->travel(23)->hours();
        $this->post(self::URI, ['current_password' => 'password', 'code' => $this->otp()])->assertSessionHasErrors('code');

        $this->travel(1)->hours();
        $this->post(self::URI, ['current_password' => 'password', 'code' => $this->otp()])->assertSessionHasNoErrors();
    }

    /** Sixty wrong codes twice over would pause the account if the right one between them did not reset the count. */
    public function test_a_right_code_resets_the_consecutive_count(): void
    {
        $this->wrongCodes(60, secondsApart: 13);

        $this->travel(61)->seconds();
        $this->post('/member/config/email', ['new_email' => 'new@example.com', 'current_password' => 'password', 'code' => $this->otp()])
            ->assertSessionHasNoErrors();

        $this->wrongCodes(60, secondsApart: 13);

        $this->travel(61)->seconds();
        $this->post('/member/config/email', ['new_email' => 'newer@example.com', 'current_password' => 'password', 'code' => $this->otp()])
            ->assertSessionHasNoErrors();
        Notification::assertNotSentTo($this->member, MfaCodesPausedNotification::class);
    }
}
