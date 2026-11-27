<?php

declare(strict_types=1);

namespace Tests\Feature\Member\Actions;

use App\Features\Member\Actions\AdministratorRoleHeld;
use App\Features\Member\Actions\AdministratorRoleResult;
use App\Features\Member\Actions\GrantAdministrator;
use App\Features\Member\Actions\RejectMemberLogin;
use App\Features\Member\Actions\RevokeAdministrator;
use App\Features\Member\Actions\WithdrawMember;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class AdministratorRoleTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureSecurityLog();
        Member::factory()->create(['id' => 1]);
    }

    private function grant(Member $member, ?Member $actor = null): AdministratorRoleResult
    {
        return app(GrantAdministrator::class)($member, $actor);
    }

    private function revoke(Member $member, ?Member $actor = null): AdministratorRoleResult
    {
        return app(RevokeAdministrator::class)($member, $actor);
    }

    private function administrator(): Member
    {
        $member = Member::factory()->create();
        $this->assertSame(AdministratorRoleResult::Granted, $this->grant($member));

        return $member;
    }

    public function test_a_grant_sets_the_role_and_steps_the_generation(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(AdministratorRoleResult::Granted, $this->grant($member));

        $this->assertTrue($member->isAdministrator());
        $this->assertTrue($member->fresh()->isAdministrator());
        $this->assertSame(1, $member->fresh()->admin_generation);
        $context = $this->assertOneSecurityEvent('admin.granted');
        $this->assertSame([(string) $member->id, 'cli'], [$context['member_id'], $context['actor']]);
    }

    public function test_granting_an_administrator_again_writes_nothing(): void
    {
        $member = $this->administrator();
        $grantedAt = $member->fresh()->admin_granted_at;
        $this->travel(5)->minutes();

        $this->assertSame(AdministratorRoleResult::AlreadyAdministrator, $this->grant($member));

        $this->assertSame(1, $member->fresh()->admin_generation);
        $this->assertEquals($grantedAt, $member->fresh()->admin_granted_at);
        $this->assertCount(1, $this->securityRecords('admin.granted'));
    }

    public function test_a_grant_is_refused_for_who_cannot_hold_the_role(): void
    {
        $ai = Member::factory()->aiAccount(Member::factory()->create())->create();
        $rejected = Member::factory()->create(['is_login_rejected' => true]);
        $withdrawing = Member::factory()->create();
        $withdrawing->forceFill(['withdrawal_started_at' => now()])->save();
        $gone = Member::factory()->create();
        $gone->delete();

        $this->assertSame(AdministratorRoleResult::AiAccount, $this->grant($ai));
        $this->assertSame(AdministratorRoleResult::LoginRejected, $this->grant($rejected));
        $this->assertSame(AdministratorRoleResult::Withdrawing, $this->grant($withdrawing));
        $this->assertSame(AdministratorRoleResult::MemberGone, $this->grant($gone));

        $this->assertSame(0, Member::whereNotNull('admin_granted_at')->count());
        $this->assertCount(0, $this->securityRecords('admin.granted'));
    }

    public function test_a_revoke_clears_the_role_and_steps_the_generation(): void
    {
        $this->administrator();
        $member = $this->administrator();

        $this->assertSame(AdministratorRoleResult::Revoked, $this->revoke($member));

        $this->assertFalse($member->fresh()->isAdministrator());
        $this->assertSame(2, $member->fresh()->admin_generation);
        $this->assertSame(AdministratorRoleResult::Granted, $this->grant($member));
        $this->assertSame(3, $member->fresh()->admin_generation);
        $this->assertSame((string) $member->id, $this->assertOneSecurityEvent('admin.revoked')['member_id']);
    }

    public function test_the_last_administrator_keeps_the_role(): void
    {
        $member = $this->administrator();

        $this->assertSame(AdministratorRoleResult::LastAdministrator, $this->revoke($member));

        $this->assertTrue($member->fresh()->isAdministrator());
        $this->assertCount(0, $this->securityRecords('admin.revoked'));
    }

    public function test_revoking_a_member_without_the_role_writes_nothing(): void
    {
        $this->administrator();
        $member = Member::factory()->create();

        $this->assertSame(AdministratorRoleResult::NotAdministrator, $this->revoke($member));

        $this->assertSame(0, $member->fresh()->admin_generation);
    }

    public function test_from_the_panel_an_administrator_cannot_revoke_their_own_role(): void
    {
        $actor = $this->administrator();
        $this->administrator();

        $this->assertSame(AdministratorRoleResult::OwnRole, $this->revoke($actor, $actor));

        $this->assertTrue($actor->fresh()->isAdministrator());
    }

    public function test_from_the_panel_an_actor_who_lost_the_role_changes_nothing(): void
    {
        $this->administrator();
        $actor = $this->administrator();
        $other = $this->administrator();
        $this->revoke($actor);
        $outsider = Member::factory()->create();

        $this->assertSame(AdministratorRoleResult::ActorNotAdministrator, $this->revoke($other, $actor));
        $this->assertSame(AdministratorRoleResult::ActorNotAdministrator, $this->grant($outsider, $actor));

        $this->assertTrue($other->fresh()->isAdministrator());
        $this->assertFalse($outsider->fresh()->isAdministrator());
    }

    public function test_a_panel_grant_logs_the_acting_administrator(): void
    {
        $actor = $this->administrator();
        $member = Member::factory()->create();

        $this->grant($member, $actor);

        $this->assertSame((string) $actor->id, $this->securityRecords('admin.granted')[1]['context']['actor']);
    }

    public function test_an_administrator_is_neither_withdrawn_nor_banned(): void
    {
        $member = $this->administrator();
        $diary = Diary::factory()->create(['member_id' => $member->id]);

        foreach ([WithdrawMember::class, RejectMemberLogin::class] as $action) {
            try {
                app($action)($member, $this->createAdmin());
                $this->fail("{$action} went through");
            } catch (AdministratorRoleHeld) {
            }
        }

        $member->refresh();
        $this->assertFalse((bool) $member->is_login_rejected);
        $this->assertNull($member->withdrawal_started_at);
        $this->assertModelExists($diary);
    }

    public function test_a_withdrawal_that_stops_part_way_keeps_the_member_from_the_role(): void
    {
        $member = Member::factory()->create();
        Member::deleted(fn () => throw new RuntimeException('stopped'));

        try {
            app(WithdrawMember::class)($member);
            $this->fail('the withdrawal went through');
        } catch (RuntimeException $e) {
            $this->assertSame('stopped', $e->getMessage());
        }

        $this->assertModelExists($member);
        $this->assertNotNull($member->fresh()->withdrawal_started_at);
        $this->assertSame(AdministratorRoleResult::Withdrawing, $this->grant($member->fresh()));
    }

    public function test_a_ban_re_reads_the_role_rather_than_trusting_the_callers_copy(): void
    {
        $member = Member::factory()->create();
        $stale = Member::findOrFail($member->id);
        $this->grant($member);

        $this->expectException(AdministratorRoleHeld::class);

        app(RejectMemberLogin::class)($stale, $this->createAdmin());
    }
}
