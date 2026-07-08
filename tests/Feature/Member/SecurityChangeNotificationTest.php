<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Actions\Fortify\ResetMemberPassword;
use App\Models\Member;
use App\Notifications\Member\MfaDisabledNotification;
use App\Notifications\Member\MfaEnabledNotification;
use App\Notifications\Member\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The takeover-detection alerts: a mail to the member's own address when their password or two-factor
 * factor changes. Mail only, always sent. A pending set-up that is cancelled is not a credential
 * change, so it raises no "two-factor disabled" alert.
 */
class SecurityChangeNotificationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_changing_the_password_notifies_the_member(): void
    {
        Notification::fake();
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'a-fresh-strong-password',
            'password_confirmation' => 'a-fresh-strong-password',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $member,
            PasswordChangedNotification::class,
            fn (PasswordChangedNotification $n, array $channels) => $channels === ['mail'],
        );
    }

    public function test_resetting_a_forgotten_password_notifies_the_member(): void
    {
        Notification::fake();
        $member = Member::factory()->create();

        app(ResetMemberPassword::class)->reset($member, [
            'password' => 'a-fresh-strong-password',
            'password_confirmation' => 'a-fresh-strong-password',
        ]);

        Notification::assertSentTo($member, PasswordChangedNotification::class);
    }

    public function test_enabling_two_factor_notifies_the_member(): void
    {
        Notification::fake();
        $member = Member::factory()->create();
        $this->actingAs($member)->post('/member/config/mfa/enable', ['current_password' => 'password']);

        $this->post('/member/config/mfa/confirm', ['code' => $this->currentOtp($member->fresh())]);

        Notification::assertSentTo($member, MfaEnabledNotification::class);
    }

    public function test_disabling_a_live_factor_notifies_the_member(): void
    {
        Notification::fake();
        $member = $this->memberWithTwoFactor();

        $this->actingAs($member)->post('/member/config/mfa/disable', ['current_password' => 'password']);

        Notification::assertSentTo($member, MfaDisabledNotification::class);
    }

    public function test_cancelling_a_pending_setup_sends_no_disable_alert(): void
    {
        Notification::fake();
        $member = Member::factory()->create();
        // A pending, unconfirmed secret — never protected a login.
        app(EnableTwoFactorAuthentication::class)($member, force: true);

        $this->actingAs($member->fresh())->post('/member/config/mfa/disable', ['current_password' => 'password']);

        Notification::assertNotSentTo($member, MfaDisabledNotification::class);
    }
}
