<?php

declare(strict_types=1);

namespace Tests\Feature\Member\Actions;

use App\Features\Member\Actions\RejectMemberLogin;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class RejectMemberLoginTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    private Member $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Reserve id 1 as the primary member so factory subjects below get id >= 2.
        Member::factory()->create(['id' => 1]);

        $this->admin = $this->createAdmin();
        $this->captureSecurityLog();
    }

    private function reject(Member $member): void
    {
        app(RejectMemberLogin::class)($member, $this->admin);
    }

    public function test_sets_the_login_rejected_flag(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => false]);

        $this->reject($member);

        $this->assertTrue($member->fresh()->is_login_rejected);
    }

    public function test_revokes_live_sessions(): void
    {
        config(['session.driver' => 'database']);
        $member = Member::factory()->create(['is_login_rejected' => false]);

        DB::table('sessions')->insert([
            'id' => 'member-device', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        $this->reject($member);

        $this->assertDatabaseMissing('sessions', ['id' => 'member-device']);
    }

    public function test_deletes_every_personal_access_token(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => false]);
        $member->createToken('mcp', ['mcp:read']);
        $member->createToken('other');
        $bystander = Member::factory()->create();
        $bystander->createToken('mcp', ['mcp:read']);

        $this->reject($member);

        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(1, $bystander->tokens()->count());
    }

    public function test_logs_the_banned_event_with_the_admin_actor(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => false]);

        $this->reject($member);

        $context = $this->assertOneSecurityEvent('member.banned');
        $this->assertSame((string) $member->getKey(), $context['member_id']);
        $this->assertSame((string) $this->admin->getKey(), $context['admin_member_id']);
    }

    public function test_primary_member_cannot_have_login_rejected_and_leaves_no_side_effects(): void
    {
        // Unreachable through the UI, which hides the action for id 1.
        config(['session.driver' => 'database']);
        $primary = Member::findOrFail(1);
        $primary->forceFill(['is_login_rejected' => false])->save();
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
        $this->assertDatabaseHas('sessions', ['id' => 'primary-device']);
        $this->assertCount(0, $this->securityRecords('member.banned'));
    }
}
