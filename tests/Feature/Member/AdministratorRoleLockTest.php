<?php

namespace Tests\Feature\Member;

use App\Features\Auth\Actions\CompleteRegistration;
use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\RegistrationLinkUnusable;
use App\Features\Auth\RegistrationTokenSource;
use App\Features\Member\Actions\AdministratorRoleHeld;
use App\Features\Member\Actions\AdministratorRoleResult;
use App\Features\Member\Actions\GrantAdministrator;
use App\Features\Member\Actions\RejectMemberLogin;
use App\Features\Member\Actions\RevokeAdministrator;
use App\Features\Member\Actions\WithdrawMember;
use App\Models\AdminRoleInvite;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\SnsSettingKey;
use ArrayObject;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\OpensSecondConnection;
use Tests\TestCase;

/**
 * Each race is fired on a second connection from inside the first writer, either once it holds its
 * lock (the race must wait) or just before it takes it (the first writer must see what the race
 * committed). A lock wait there is caught and reported, since letting it escape would roll the first
 * writer back and pass.
 */
class AdministratorRoleLockTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, OpensSecondConnection;

    private const LOCK_WAIT = 1205;

    private const ADMINISTRATORS = '/from `members` where `admin_granted_at` is not null.*for update/is';

    private const MEMBER_ROW = '/from `members` where `members`.`id` = \?.*for update/is';

    private const CAPACITY = '/from `sns_settings` where `key` = \?.*for update/is';

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Two connections need a server; sqlite :memory: has one.');
        }

        $this->openSecondConnection();
        Member::factory()->create(['id' => 1]);
    }

    protected function tearDown(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->closeSecondConnection();
        }

        parent::tearDown();
    }

    private function administrator(): Member
    {
        $member = Member::factory()->create();
        $member->forceFill(['admin_granted_at' => now(), 'admin_generation' => 1])->save();

        return $member;
    }

    public function test_a_revoke_arriving_while_another_holds_the_administrators_waits_and_one_remains(): void
    {
        $first = $this->administrator();
        $second = $this->administrator();

        $outcomes = $this->after(self::ADMINISTRATORS, fn (): string => $this->raced(fn () => app(RevokeAdministrator::class)($second)));
        $this->assertSame(AdministratorRoleResult::Revoked, app(RevokeAdministrator::class)($first));

        $this->assertWaitedOn(self::ADMINISTRATORS, $outcomes);
        $this->assertTrue($second->fresh()->isAdministrator());
        $this->assertSame(1, Member::whereNotNull('admin_granted_at')->count());
    }

    public function test_a_revoke_committed_just_before_the_lock_leaves_the_other_as_the_last(): void
    {
        $first = $this->administrator();
        $second = $this->administrator();

        $outcomes = $this->before(self::ADMINISTRATORS, fn (): string => $this->raced(fn () => app(RevokeAdministrator::class)($second)));
        $this->assertSame(AdministratorRoleResult::LastAdministrator, app(RevokeAdministrator::class)($first));

        $this->assertSame(['done'], $outcomes->getArrayCopy());
        $this->assertTrue($first->fresh()->isAdministrator());
        $this->assertFalse($second->fresh()->isAdministrator());
    }

    public function test_a_ban_arriving_while_the_grant_holds_the_member_waits(): void
    {
        $member = Member::factory()->create();

        $outcomes = $this->after(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(RejectMemberLogin::class)($member)));
        $this->assertSame(AdministratorRoleResult::Granted, app(GrantAdministrator::class)($member));

        $this->assertWaitedOn(self::MEMBER_ROW, $outcomes);
        $this->assertTrue($member->fresh()->isAdministrator());
        $this->assertFalse((bool) $member->fresh()->is_login_rejected);
    }

    public function test_a_grant_arriving_while_the_ban_holds_the_member_waits(): void
    {
        $member = Member::factory()->create();

        $outcomes = $this->after(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(GrantAdministrator::class)($member)));
        app(RejectMemberLogin::class)($member);

        $this->assertWaitedOn(self::MEMBER_ROW, $outcomes);
        $this->assertTrue((bool) $member->fresh()->is_login_rejected);
        $this->assertFalse($member->fresh()->isAdministrator());
    }

    public function test_a_ban_committed_just_before_the_grants_lock_refuses_the_grant(): void
    {
        $member = Member::factory()->create();

        $outcomes = $this->before(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(RejectMemberLogin::class)($member)));

        $this->assertSame(AdministratorRoleResult::LoginRejected, app(GrantAdministrator::class)($member));
        $this->assertSame(['done'], $outcomes->getArrayCopy());
        $this->assertFalse($member->fresh()->isAdministrator());
    }

    public function test_a_grant_committed_just_before_the_bans_lock_refuses_the_ban(): void
    {
        $member = Member::factory()->create();

        $outcomes = $this->before(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(GrantAdministrator::class)($member)));

        $this->expectException(AdministratorRoleHeld::class);
        try {
            app(RejectMemberLogin::class)($member);
        } finally {
            $this->assertSame(['done'], $outcomes->getArrayCopy());
            $this->assertFalse((bool) $member->fresh()->is_login_rejected);
        }
    }

    public function test_a_withdrawal_arriving_while_the_grant_holds_the_member_waits(): void
    {
        $member = Member::factory()->create();

        $outcomes = $this->after(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(WithdrawMember::class)($member)));
        $this->assertSame(AdministratorRoleResult::Granted, app(GrantAdministrator::class)($member));

        $this->assertWaitedOn(self::MEMBER_ROW, $outcomes);
        $this->assertTrue($member->fresh()->isAdministrator());
        $this->assertNull($member->fresh()->withdrawal_started_at);
    }

    public function test_a_grant_arriving_while_the_withdrawal_marks_the_member_waits(): void
    {
        $member = Member::factory()->create();

        $outcomes = $this->after(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(GrantAdministrator::class)($member)));
        app(WithdrawMember::class)($member);

        $this->assertWaitedOn(self::MEMBER_ROW, $outcomes);
        $this->assertModelMissing($member);
    }

    public function test_a_withdrawal_under_way_refuses_a_grant_that_comes_after_the_mark(): void
    {
        $member = Member::factory()->create();
        Member::deleted(fn () => throw new RuntimeException('still purging'));

        $outcomes = $this->before(self::MEMBER_ROW, function () use ($member): string {
            try {
                return $this->raced(fn () => app(WithdrawMember::class)($member));
            } catch (RuntimeException) {
                return 'stopped';
            }
        });

        $this->assertSame(AdministratorRoleResult::Withdrawing, app(GrantAdministrator::class)($member));
        $this->assertSame(['stopped'], $outcomes->getArrayCopy());
        $this->assertFalse($member->fresh()->isAdministrator());
    }

    public function test_a_grant_committed_just_before_the_withdrawals_mark_refuses_the_withdrawal(): void
    {
        $member = Member::factory()->create();

        $outcomes = $this->before(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(GrantAdministrator::class)($member)));

        $this->expectException(AdministratorRoleHeld::class);
        try {
            app(WithdrawMember::class)($member);
        } finally {
            $this->assertSame(['done'], $outcomes->getArrayCopy());
            $this->assertModelExists($member);
            $this->assertNull($member->fresh()->withdrawal_started_at);
        }
    }

    private function adminLink(string $email): RegistrationToken
    {
        $raw = Str::random(40);
        RegistrationToken::create(['email' => $email, 'token' => hash('sha256', $raw), 'source' => RegistrationTokenSource::AdminRoleInvite, 'created_at' => now()]);
        AdminRoleInvite::create(['email' => $email, 'expires_at' => now()->addDay()->getTimestamp(), 'created_at' => now()]);

        return RegistrationToken::where('email', $email)->sole();
    }

    private function reinvite(string $email): void
    {
        $result = app(IssueRegistrationToken::class)->unmailedLink($email, RegistrationTokenSource::AdminRoleInvite, function (CarbonInterface $createdAt) use ($email): void {
            AdminRoleInvite::upsert([['email' => $email, 'expires_at' => $createdAt->copy()->addDay()->getTimestamp(), 'created_at' => $createdAt]], ['email'], ['expires_at', 'created_at']);
        });
        $this->assertIsString($result);
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return ['name' => 'Newcomer', 'password' => 'sufficiently-long-pw', 'password_confirmation' => 'sufficiently-long-pw'];
    }

    public function test_a_new_invite_arriving_while_the_registration_holds_the_capacity_waits(): void
    {
        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 100);
        $pending = $this->adminLink('admin@example.com');

        $outcomes = $this->after(self::CAPACITY, fn (): string => $this->raced(fn () => $this->reinvite('admin@example.com')));
        $member = app(CompleteRegistration::class)($pending, $this->form());

        $this->assertWaitedOn('/from `sns_settings`.*for update/is', $outcomes);
        $this->assertTrue($member->fresh()->isAdministrator());
        $this->assertDatabaseCount('admin_role_invites', 0);
        $this->assertDatabaseCount('registration_tokens', 0);
    }

    /** With no cap row the capacity gates hold only gap locks, which do not exclude each other: the link's row is what serializes them. */
    public function test_without_a_cap_row_a_new_invite_waits_on_the_link_the_registration_re_read(): void
    {
        $pending = $this->adminLink('admin@example.com');

        $outcomes = $this->after('/from `admin_role_invites`.*for update/is', fn (): string => $this->raced(fn () => $this->reinvite('admin@example.com')));
        $member = app(CompleteRegistration::class)($pending, $this->form());

        $this->assertWaitedOn('/insert into `registration_tokens`/i', $outcomes);
        $this->assertTrue($member->fresh()->isAdministrator());
        $this->assertDatabaseCount('admin_role_invites', 0);
    }

    public function test_a_new_invite_committed_just_before_the_registration_voids_the_old_link(): void
    {
        $pending = $this->adminLink('admin@example.com');

        $outcomes = $this->before(self::CAPACITY, fn (): string => $this->raced(fn () => $this->reinvite('admin@example.com')));

        try {
            app(CompleteRegistration::class)($pending, $this->form());
            $this->fail('the replaced link registered');
        } catch (RegistrationLinkUnusable $e) {
            $this->assertFalse($e->refused);
        }

        $this->assertSame(['done'], $outcomes->getArrayCopy());
        $this->assertDatabaseMissing('members', ['email' => 'admin@example.com']);
        $this->assertDatabaseCount('admin_role_invites', 1);
        $this->assertNotSame($pending->token, RegistrationToken::where('email', 'admin@example.com')->sole()->token);
    }

    public function test_an_invite_expiring_before_the_registration_locks_grants_nothing(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $raw = Str::random(40);
        RegistrationToken::create(['email' => 'admin@example.com', 'token' => hash('sha256', $raw), 'source' => RegistrationTokenSource::MemberInvite, 'created_at' => now()]);
        AdminRoleInvite::create(['email' => 'admin@example.com', 'expires_at' => now()->addDay()->getTimestamp(), 'created_at' => now()]);
        $pending = RegistrationToken::sole();

        $outcomes = $this->before(self::CAPACITY, fn (): string => $this->raced(fn () => AdminRoleInvite::query()->update(['expires_at' => now()->getTimestamp() - 1])));
        $member = app(CompleteRegistration::class)($pending, $this->form());

        $this->assertSame(['done'], $outcomes->getArrayCopy());
        $this->assertFalse($member->fresh()->isAdministrator());
        $this->assertDatabaseCount('admin_role_invites', 1);
    }

    /**
     * Runs $race once, after the default connection's first statement matching $pattern.
     *
     * @param  callable(): string  $race
     * @return ArrayObject<int, string>
     */
    private function after(string $pattern, callable $race): ArrayObject
    {
        $outcomes = new ArrayObject;
        $connection = DB::getDefaultConnection();

        DB::listen(function (QueryExecuted $query) use ($pattern, $race, $connection, $outcomes): void {
            if ($query->connectionName !== $connection || ! preg_match($pattern, $query->sql) || count($outcomes) > 0) {
                return;
            }
            $outcomes[] = 'running';
            $outcomes[0] = $race();
        });

        return $outcomes;
    }

    /**
     * Runs $race once, just before the default connection's first statement matching $pattern.
     *
     * @param  callable(): string  $race
     * @return ArrayObject<int, string>
     */
    private function before(string $pattern, callable $race): ArrayObject
    {
        $outcomes = new ArrayObject;
        $connection = DB::getDefaultConnection();

        DB::beforeExecuting(function (string $sql, array $bindings, Connection $on) use ($pattern, $race, $connection, $outcomes): void {
            if ($on->getName() !== $connection || ! preg_match($pattern, $sql) || count($outcomes) > 0) {
                return;
            }
            $outcomes[] = 'running';
            $outcomes[0] = $race();
        });

        return $outcomes;
    }

    /** @return string 'done' | 'waited:<the statement that timed out>' */
    private function raced(callable $write): string
    {
        return $this->onSecondConnection(function () use ($write): string {
            try {
                $write();

                return 'done';
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== self::LOCK_WAIT) {
                    throw $e;
                }

                return 'waited:'.$e->getSql();
            }
        });
    }

    /** @param  ArrayObject<int, string>  $outcomes */
    private function assertWaitedOn(string $statement, ArrayObject $outcomes): void
    {
        $this->assertCount(1, $outcomes, 'the race did not run exactly once');
        $this->assertStringStartsWith('waited:', $outcomes[0], "the race did not wait ({$outcomes[0]})");
        $sql = substr($outcomes[0], strlen('waited:'));
        $this->assertMatchesRegularExpression($statement, $sql, "waited on the wrong statement: {$sql}");
    }
}
