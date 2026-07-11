<?php

declare(strict_types=1);

namespace Tests\Feature\Member\Actions;

use App\Features\Member\Actions\AllowMemberLogin;
use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class AllowMemberLoginTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create();
        $this->actingAs($this->admin, 'admin');
        $this->captureSecurityLog();
    }

    private function allow(Member $member): void
    {
        app(AllowMemberLogin::class)($member);
    }

    public function test_clears_the_login_rejected_flag(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => true]);

        $this->allow($member);

        $this->assertFalse($member->fresh()->is_login_rejected);
    }

    public function test_logs_the_unbanned_event_with_the_admin_actor(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => true]);

        $this->allow($member);

        $context = $this->assertOneSecurityEvent('member.unbanned');
        $this->assertSame((string) $member->getKey(), $context['member_id']);
        $this->assertSame($this->admin->username, $context['admin_username']);
    }
}
