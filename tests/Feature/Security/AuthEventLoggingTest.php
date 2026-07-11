<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Features\Auth\Events\MemberRegistered;
use App\Models\Member;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

/**
 * The auto-discovered Security listeners: each framework/Fortify auth event produces exactly one
 * security record with the expected event name. Two events are additionally inspected for the
 * contract (the failed-login identifier without the password; the success guard).
 */
class AuthEventLoggingTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake(); // MemberRegistered also drives a mail notification we don't exercise here.
        $this->captureSecurityLog();
    }

    /** @return array<string, array{Closure, string}> */
    public static function events(): array
    {
        return [
            'login.success' => [fn (Member $m) => new Login('member', $m, true), 'login.success'],
            'login.failed' => [fn (Member $m) => new Failed('member', null, ['email' => 'who@example.com', 'password' => 'secret-pw']), 'login.failed'],
            'login.lockout' => [fn (Member $m) => new Lockout(Request::create('/login', 'POST', ['email' => 'who@example.com'])), 'login.lockout'],
            'logout' => [fn (Member $m) => new Logout('member', $m), 'logout'],
            'password.reset' => [fn (Member $m) => new PasswordReset($m), 'password.reset'],
            'mfa.failed' => [fn (Member $m) => new TwoFactorAuthenticationFailed($m), 'mfa.failed'],
            'mfa.recovery_code_used' => [fn (Member $m) => new RecoveryCodeReplaced($m, 'plain-recovery-code'), 'mfa.recovery_code_used'],
            'member.registered' => [fn (Member $m) => new MemberRegistered($m), 'member.registered'],
        ];
    }

    #[DataProvider('events')]
    public function test_each_auth_event_logs_exactly_one_security_record(Closure $make, string $expected): void
    {
        $member = Member::factory()->create();

        event($make($member));

        $this->assertCount(1, $this->securityRecords(), 'the event should produce a single security record');
        $this->assertOneSecurityEvent($expected);
    }

    public function test_failed_login_logs_the_identifier_but_never_the_password(): void
    {
        event(new Failed('member', null, ['email' => 'who@example.com', 'password' => 'secret-pw']));

        $context = $this->assertOneSecurityEvent('login.failed');
        $this->assertSame('who@example.com', $context['identifier']);
        $this->assertStringNotContainsString('secret-pw', json_encode($this->securityRecords('login.failed')));
    }

    public function test_recovery_code_use_never_logs_the_code(): void
    {
        $member = Member::factory()->create();

        event(new RecoveryCodeReplaced($member, 'plain-recovery-code'));

        $this->assertStringNotContainsString('plain-recovery-code', json_encode($this->securityRecords()));
    }

    public function test_successful_login_carries_the_guard_and_remember_flag(): void
    {
        $member = Member::factory()->create();

        event(new Login('member', $member, true));

        $context = $this->assertOneSecurityEvent('login.success');
        $this->assertSame('member', $context['guard']);
        $this->assertSame('true', $context['remember']);
        $this->assertSame((string) $member->getKey(), $context['member_id']);
    }
}
