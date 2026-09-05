<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Actions\Fortify\ResetMemberPassword;
use App\Features\AiAccount\Actions\CreateAiAccount;
use App\Features\Member\Actions\RejectMemberLogin;
use App\Features\Member\Actions\WithdrawMember;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Notifications\Member\WithdrawalAdminNotification;
use App\Support\SnsSettingKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AiAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reserve id 1 as the un-withdrawable primary member so factory subjects get id >= 2.
        Member::factory()->create(['id' => 1]);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
    }

    private function tokenCount(Member $member): int
    {
        return $member->tokens()->count();
    }

    public function test_freezing_an_owner_ends_the_tokens_of_every_account_they_own(): void
    {
        $owner = Member::factory()->create();
        $first = Member::factory()->aiAccount($owner)->create();
        $second = Member::factory()->aiAccount($owner)->create();
        $bystander = Member::factory()->aiAccount()->create();

        foreach ([$first, $second, $bystander] as $account) {
            $account->createToken('mcp', ['mcp:read']);
        }

        app(RejectMemberLogin::class)($owner);

        $this->assertSame(0, $this->tokenCount($first));
        $this->assertSame(0, $this->tokenCount($second));
        $this->assertSame(1, $this->tokenCount($bystander), "another owner's account is untouched");
    }

    public function test_an_admin_can_freeze_an_ai_account_directly(): void
    {
        // The ban's remember-token rotation would be a credential write on a row the members
        // constraint admits none on.
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $aiAccount->createToken('mcp', ['mcp:read']);

        app(RejectMemberLogin::class)($aiAccount);

        $fresh = $aiAccount->fresh();
        $this->assertTrue($fresh->is_login_rejected);
        $this->assertNull($fresh->remember_token);
        $this->assertSame(0, $this->tokenCount($aiAccount));
    }

    public function test_freezing_an_ordinary_member_still_rotates_their_remember_token(): void
    {
        // The control for the skip above: a member who does hold a remember-me cookie has it ended.
        $member = Member::factory()->create();
        $before = $member->remember_token;

        app(RejectMemberLogin::class)($member);

        $this->assertNotNull($before);
        $this->assertNotSame($before, $member->fresh()->remember_token);
    }

    public function test_a_frozen_owner_closes_the_endpoint_to_an_ai_accounts_surviving_token(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureMcpEnabled, true);
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $token = $aiAccount->createToken('mcp', ['mcp:read', 'mcp:write'])->plainTextToken;

        // The ban sweeps the tokens in the same transaction as the flag, so the flag is set directly
        // here: what is under test is the belt behind that sweep, on the owner's side of the link.
        $owner->forceFill(['is_login_rejected' => true])->save();

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnauthorized();
    }

    public function test_an_unfrozen_owners_ai_account_still_reaches_the_endpoint(): void
    {
        // The control for the case above: the 401 has to come from the freeze, not from being an AI
        // account.
        $this->setSnsSetting(SnsSettingKey::FeatureMcpEnabled, true);
        $aiAccount = Member::factory()->aiAccount()->create();
        $token = $aiAccount->createToken('mcp', ['mcp:read', 'mcp:write'])->plainTextToken;

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();
    }

    public function test_resetting_a_forgotten_owner_password_revokes_owned_ai_tokens(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $aiAccount->createToken('mcp', ['mcp:read']);

        app(ResetMemberPassword::class)->reset($owner, [
            'password' => 'a-fresh-strong-password',
            'password_confirmation' => 'a-fresh-strong-password',
        ]);

        $this->assertSame(0, $this->tokenCount($aiAccount));
    }

    public function test_changing_the_owner_password_in_session_revokes_owned_ai_tokens(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $aiAccount->createToken('mcp', ['mcp:read']);

        $this->actingAs($owner)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'a-fresh-strong-password',
            'password_confirmation' => 'a-fresh-strong-password',
        ])->assertRedirect();

        $this->assertSame(0, $this->tokenCount($aiAccount));
    }

    public function test_withdrawing_an_owner_retires_the_accounts_they_own(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $aiAccount->createToken('mcp', ['mcp:read']);

        // The account has a life of its own to unwind: a seat and a diary, neither of which the
        // members cascade could have cleaned up.
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $aiAccount->getKey()]);
        $diary = Diary::factory()->create(['member_id' => $aiAccount->getKey()]);

        app(WithdrawMember::class)($owner);

        $this->assertModelMissing($owner);
        $this->assertModelMissing($aiAccount);
        $this->assertModelMissing($diary);
        $this->assertDatabaseMissing('group_members', ['member_id' => $aiAccount->getKey()]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $aiAccount->getKey()]);
    }

    public function test_only_the_owners_withdrawal_reaches_the_operator(): void
    {
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');
        $owner = Member::factory()->create(['name' => 'Leaver']);
        Member::factory()->aiAccount($owner)->create();
        Member::factory()->aiAccount($owner)->create();

        app(WithdrawMember::class)($owner);

        // Three members left, one notice: the two AI accounts are the owner tidying up, not members
        // leaving the site.
        Notification::assertSentOnDemandTimes(WithdrawalAdminNotification::class, 1);
        Notification::assertSentOnDemand(
            WithdrawalAdminNotification::class,
            fn (WithdrawalAdminNotification $n): bool => $n->memberId === (int) $owner->getKey(),
        );
    }

    public function test_an_account_created_mid_withdrawal_is_still_retired(): void
    {
        // Concurrency without concurrency: a one-shot listener on the diary purge phase creates an
        // AI account after the first drain has already run.
        $owner = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey()]);

        $injected = false;
        Diary::deleted(function () use (&$injected, $owner): void {
            if ($injected) {
                return;
            }
            $injected = true;

            app(CreateAiAccount::class)($owner, 'Late arrival');
        });

        app(WithdrawMember::class)($owner);

        $this->assertTrue($injected, 'the mid-withdrawal creation was never exercised');
        $this->assertModelMissing($owner);
        $this->assertDatabaseMissing('members', ['owner_member_id' => $owner->getKey()]);
    }

    public function test_deleting_an_owner_row_with_a_live_account_fails_loud(): void
    {
        $owner = Member::factory()->create();
        Member::factory()->aiAccount($owner)->create();

        $this->expectException(QueryException::class);
        DB::table('members')->where('id', $owner->getKey())->delete();
    }
}
