<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Models\MfaResetRequest;
use App\Notifications\Member\MfaDisabledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

/** The pins below fix the command's observed behavior against the shared ForceDisableMemberMfa core. */
class DisableMemberMfaCommandTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    public function test_it_disables_mfa_and_revokes_sessions(): void
    {
        config(['session.driver' => 'database']);
        $member = Member::factory()->create(['email' => 'amy@example.com']);
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now(), 'remember_token' => Str::random(60)])->save();
        $before = $member->fresh()->remember_token;

        DB::table('sessions')->insert([
            'id' => 'device', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        // The email argument is canonicalized like the login form's (stored emails are lowercase).
        $this->artisan('openpne:member:disable-mfa', ['email' => 'AMY@example.com'])->assertSuccessful();

        $member->refresh();
        $this->assertNull($member->two_factor_secret);
        $this->assertNull($member->two_factor_recovery_codes);
        $this->assertNull($member->two_factor_confirmed_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'device']);
        $this->assertNotSame($before, $member->remember_token);
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $this->artisan('openpne:member:disable-mfa', ['email' => 'ghost@example.com'])->assertFailed();
    }

    public function test_it_alerts_the_member_when_a_live_factor_was_removed(): void
    {
        Notification::fake();
        $member = Member::factory()->create(['email' => 'amy@example.com']);
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->artisan('openpne:member:disable-mfa', ['email' => 'amy@example.com'])->assertSuccessful();

        Notification::assertSentTo($member, MfaDisabledNotification::class);
    }

    public function test_it_sends_no_alert_when_no_live_factor_was_present(): void
    {
        Notification::fake();
        $member = Member::factory()->create(['email' => 'amy@example.com']);
        app(EnableTwoFactorAuthentication::class)($member, force: true);

        $this->artisan('openpne:member:disable-mfa', ['email' => 'amy@example.com'])->assertSuccessful();

        Notification::assertNotSentTo($member, MfaDisabledNotification::class);
    }

    public function test_it_logs_the_disable_via_cli(): void
    {
        $this->captureSecurityLog();
        $member = Member::factory()->create(['email' => 'amy@example.com']);
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->artisan('openpne:member:disable-mfa', ['email' => 'amy@example.com'])->assertSuccessful();

        $context = $this->assertOneSecurityEvent('mfa.disabled');
        $this->assertSame('cli', $context['via']);
        $this->assertSame((string) $member->getKey(), $context['member_id']);
    }

    public function test_it_revokes_all_sessions_even_for_a_pending_setup(): void
    {
        // Pinned because ForceDisableMemberMfa revokes unconditionally, with or without a live factor.
        config(['session.driver' => 'database']);
        $member = Member::factory()->create(['email' => 'amy@example.com']);
        app(EnableTwoFactorAuthentication::class)($member, force: true); // pending, never confirmed
        $member->forceFill(['remember_token' => Str::random(60)])->save();
        $before = $member->fresh()->remember_token;

        DB::table('sessions')->insert([
            'id' => 'device', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        $this->artisan('openpne:member:disable-mfa', ['email' => 'amy@example.com'])->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => 'device']);
        $this->assertNotSame($before, $member->fresh()->remember_token);
    }

    public function test_it_drops_a_pending_admin_reset_link(): void
    {
        $member = Member::factory()->create(['email' => 'amy@example.com']);
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();
        MfaResetRequest::create([
            'member_id' => $member->getKey(), 'token' => hash('sha256', Str::random(40)), 'created_at' => now(),
        ]);

        $this->artisan('openpne:member:disable-mfa', ['email' => 'amy@example.com'])->assertSuccessful();

        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]);
    }
}
