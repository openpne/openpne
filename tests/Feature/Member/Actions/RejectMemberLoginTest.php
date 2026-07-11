<?php

declare(strict_types=1);

namespace Tests\Feature\Member\Actions;

use App\Features\Member\Actions\RejectMemberLogin;
use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class RejectMemberLoginTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The admin actor supplies admin_username on the security event; the guard also proves the
        // action never touches admin-realm sessions when it revokes a member.
        $this->admin = AdminUser::factory()->create();
        $this->actingAs($this->admin, 'admin');
        $this->captureSecurityLog();

        // Reserve id 1 as the primary member so factory subjects below get id >= 2.
        Member::factory()->create(['id' => 1]);
    }

    private function reject(Member $member): void
    {
        app(RejectMemberLogin::class)($member);
    }

    public function test_sets_the_login_rejected_flag(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => false]);

        $this->reject($member);

        $this->assertTrue($member->fresh()->is_login_rejected);
    }

    public function test_revokes_live_sessions_and_rotates_the_remember_token(): void
    {
        // The freeze flag only blocks the NEXT login; the action must also end what already
        // exists — server-side sessions and remember-me cookies — immediately.
        config(['session.driver' => 'database']);
        $member = Member::factory()->create(['is_login_rejected' => false]);
        $member->forceFill(['remember_token' => Str::random(60)])->save();
        $before = $member->remember_token;

        DB::table('sessions')->insert([
            'id' => 'member-device', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        $this->reject($member);

        $this->assertDatabaseMissing('sessions', ['id' => 'member-device']);
        $this->assertNotSame($before, $member->fresh()->remember_token);
    }

    public function test_logs_the_banned_event_with_the_admin_actor(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => false]);

        $this->reject($member);

        $context = $this->assertOneSecurityEvent('member.banned');
        $this->assertSame((string) $member->getKey(), $context['member_id']);
        $this->assertSame($this->admin->username, $context['admin_username']);
    }

    public function test_primary_member_cannot_have_login_rejected_and_leaves_no_side_effects(): void
    {
        // Unreachable through the UI (the action is hidden and halted for id 1); the guard is the
        // last line of defense, so it is asserted only here.
        config(['session.driver' => 'database']);
        $primary = Member::findOrFail(1);
        $primary->forceFill(['is_login_rejected' => false, 'remember_token' => Str::random(60)])->save();
        $before = $primary->remember_token;
        DB::table('sessions')->insert([
            'id' => 'primary-device', 'user_id' => $primary->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        try {
            $this->reject($primary);
            $this->fail('Expected the primary member to be un-rejectable.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($primary->fresh()->is_login_rejected);
        $this->assertSame($before, $primary->fresh()->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'primary-device']);
        $this->assertCount(0, $this->securityRecords('member.banned'));
    }
}
