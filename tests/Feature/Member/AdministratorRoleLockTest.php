<?php

namespace Tests\Feature\Member;

use App\Features\Auth\Actions\CompleteRegistration;
use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\Actions\IssueResult;
use App\Features\Auth\RegistrationLinkUnusable;
use App\Features\Auth\RegistrationTokenSource;
use App\Features\Member\Actions\AdministratorRoleHeld;
use App\Features\Member\Actions\AdministratorRoleResult;
use App\Features\Member\Actions\GrantAdministrator;
use App\Features\Member\Actions\RejectMemberLogin;
use App\Features\Member\Actions\RevokeAdministrator;
use App\Features\Member\Actions\WithdrawMember;
use App\Filament\Pages\RegistrationSettings;
use App\Models\AdminRoleInvite;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\SnsSettingKey;
use ArrayObject;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PDOException;
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

    /** The racer's own instance: the race must not reach the caller's copy except through the database. */
    private function copy(Member $member): Member
    {
        return Member::findOrFail($member->getKey());
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

        $outcomes = $this->after(self::ADMINISTRATORS, fn (): string => $this->raced(fn () => app(RevokeAdministrator::class)($this->copy($second))));
        $this->assertSame(AdministratorRoleResult::Revoked, app(RevokeAdministrator::class)($first));

        $this->assertWaitedOn(self::ADMINISTRATORS, $outcomes);
        $this->assertTrue($second->fresh()->isAdministrator());
        $this->assertSame(1, Member::whereNotNull('admin_granted_at')->count());
    }

    public function test_a_revoke_committed_just_before_the_lock_leaves_the_other_as_the_last(): void
    {
        $first = $this->administrator();
        $second = $this->administrator();

        $outcomes = $this->before(self::ADMINISTRATORS, fn (): string => $this->raced(fn () => app(RevokeAdministrator::class)($this->copy($second))));
        $this->assertSame(AdministratorRoleResult::LastAdministrator, app(RevokeAdministrator::class)($first));

        $this->assertSame(['done'], $outcomes->getArrayCopy());
        $this->assertTrue($first->fresh()->isAdministrator());
        $this->assertFalse($second->fresh()->isAdministrator());
    }

    public function test_a_ban_arriving_while_the_grant_holds_the_member_waits(): void
    {
        $member = Member::factory()->create();
        $actor = $this->createAdmin();

        $outcomes = $this->after(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(RejectMemberLogin::class)($this->copy($member), $actor)));
        $this->assertSame(AdministratorRoleResult::Granted, app(GrantAdministrator::class)($member));

        $this->assertWaitedOn(self::MEMBER_ROW, $outcomes);
        $this->assertTrue($member->fresh()->isAdministrator());
        $this->assertFalse((bool) $member->fresh()->is_login_rejected);
    }

    public function test_a_grant_arriving_while_the_ban_holds_the_member_waits(): void
    {
        $member = Member::factory()->create();
        $actor = $this->createAdmin();

        $outcomes = $this->after(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(GrantAdministrator::class)($this->copy($member))));
        app(RejectMemberLogin::class)($member, $actor);

        $this->assertWaitedOn(self::MEMBER_ROW, $outcomes);
        $this->assertTrue((bool) $member->fresh()->is_login_rejected);
        $this->assertFalse($member->fresh()->isAdministrator());
    }

    public function test_a_ban_committed_just_before_the_grants_lock_refuses_the_grant(): void
    {
        $member = Member::factory()->create();
        $actor = $this->createAdmin();

        $outcomes = $this->before(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(RejectMemberLogin::class)($this->copy($member), $actor)));

        $this->assertSame(AdministratorRoleResult::LoginRejected, app(GrantAdministrator::class)($member));
        $this->assertSame(['done'], $outcomes->getArrayCopy());
        $this->assertFalse($member->fresh()->isAdministrator());
    }

    public function test_a_grant_committed_just_before_the_bans_lock_refuses_the_ban(): void
    {
        $member = Member::factory()->create();
        $actor = $this->createAdmin();

        $outcomes = $this->before(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(GrantAdministrator::class)($this->copy($member))));

        $this->expectException(AdministratorRoleHeld::class);
        try {
            app(RejectMemberLogin::class)($member, $actor);
        } finally {
            $this->assertSame(['done'], $outcomes->getArrayCopy());
            $this->assertFalse((bool) $member->fresh()->is_login_rejected);
        }
    }

    public function test_a_withdrawal_arriving_while_the_grant_holds_the_member_waits(): void
    {
        $member = Member::factory()->create();

        $outcomes = $this->after(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(WithdrawMember::class)($this->copy($member))));
        $this->assertSame(AdministratorRoleResult::Granted, app(GrantAdministrator::class)($member));

        $this->assertWaitedOn(self::MEMBER_ROW, $outcomes);
        $this->assertTrue($member->fresh()->isAdministrator());
        $this->assertNull($member->fresh()->withdrawal_started_at);
    }

    public function test_a_grant_arriving_while_the_withdrawal_marks_the_member_waits(): void
    {
        $member = Member::factory()->create();

        $outcomes = $this->after(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(GrantAdministrator::class)($this->copy($member))));
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
                return $this->raced(fn () => app(WithdrawMember::class)($this->copy($member)));
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

        $outcomes = $this->before(self::MEMBER_ROW, fn (): string => $this->raced(fn () => app(GrantAdministrator::class)($this->copy($member))));

        $this->expectException(AdministratorRoleHeld::class);
        try {
            app(WithdrawMember::class)($member);
        } finally {
            $this->assertSame(['done'], $outcomes->getArrayCopy());
            $this->assertModelExists($member);
            $this->assertNull($member->fresh()->withdrawal_started_at);
        }
    }

    public function test_a_grant_or_revoke_rolled_back_by_a_deadlock_runs_again(): void
    {
        $administrator = $this->administrator();
        $member = Member::factory()->create();

        $this->deadlockOnce();
        $this->assertSame(AdministratorRoleResult::Granted, app(GrantAdministrator::class)($member));

        $this->deadlockOnce();
        $this->assertSame(AdministratorRoleResult::Revoked, app(RevokeAdministrator::class)($administrator));
    }

    public function test_a_registration_or_a_registration_settings_save_rolled_back_by_a_deadlock_runs_again(): void
    {
        $thrown = $this->deadlockOnce(self::CAPACITY);
        $member = app(CompleteRegistration::class)($this->adminLink('admin@example.com'), $this->form());
        $this->assertCount(1, $thrown);
        $this->assertTrue($member->fresh()->isAdministrator());

        Filament::setCurrentPanel('admin');
        $this->actingAsAdmin();
        DB::table('sns_settings')->where('key', SnsSettingKey::RegistrationMode->value)->delete();
        $thrown = $this->deadlockOnce('/insert into `sns_settings`/i');
        Livewire::test(RegistrationSettings::class)->fillForm(['registration_mode' => 'closed'])->call('save')->assertHasNoErrors();
        $this->assertCount(1, $thrown);
        $this->assertSame('closed', DB::table('sns_settings')->where('key', SnsSettingKey::RegistrationMode->value)->value('value'));
    }

    /**
     * InnoDB's answer to a write meeting another writer on the same gaps, which a single connection cannot provoke.
     *
     * @return ArrayObject<int, string> the statement it failed, once it has
     */
    private function deadlockOnce(string $pattern = self::ADMINISTRATORS): ArrayObject
    {
        $thrown = new ArrayObject;

        DB::beforeExecuting(function (string $sql, array $bindings, Connection $on) use ($thrown, $pattern): void {
            if (count($thrown) > 0 || ! preg_match($pattern, $sql)) {
                return;
            }
            $thrown[] = $sql;

            throw new QueryException($on->getName(), $sql, $bindings, new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        });

        return $thrown;
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

    public function test_a_registration_committed_just_before_a_new_invite_leaves_neither_a_link_nor_an_invite(): void
    {
        $pending = $this->adminLink('admin@example.com');

        $outcomes = $this->before(self::CAPACITY, fn (): string => $this->raced(fn () => app(CompleteRegistration::class)($pending, $this->form())));
        $result = app(IssueRegistrationToken::class)->unmailedLink('admin@example.com', RegistrationTokenSource::AdminRoleInvite, function (CarbonInterface $createdAt): void {
            AdminRoleInvite::upsert([['email' => 'admin@example.com', 'expires_at' => $createdAt->copy()->addDay()->getTimestamp(), 'created_at' => $createdAt]], ['email'], ['expires_at', 'created_at']);
        });

        $this->assertSame(['done'], $outcomes->getArrayCopy());
        $this->assertSame(IssueResult::AlreadyMember, $result);
        $this->assertTrue(Member::where('email', 'admin@example.com')->sole()->isAdministrator());
        $this->assertDatabaseCount('registration_tokens', 0);
        $this->assertDatabaseCount('admin_role_invites', 0);
    }

    public function test_a_mode_change_arriving_while_the_registration_holds_the_mode_waits(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $pending = $this->adminLink('admin@example.com');

        $outcomes = $this->after('/from `sns_settings` where `key` = \?.*lock in share mode/is', fn (): string => $this->raced(fn () => DB::table('sns_settings')->where('key', SnsSettingKey::RegistrationMode->value)->update(['value' => 'closed'])));
        $member = app(CompleteRegistration::class)($pending, $this->form());

        $this->assertWaitedOn('/update `sns_settings`/i', $outcomes);
        $this->assertTrue($member->fresh()->isAdministrator());
    }

    /** A member committed just as the link is written stands for a registration the upsert waited out. */
    public function test_a_registration_committing_while_the_link_is_written_still_refuses_the_invite(): void
    {
        $outcomes = $this->before('/insert into `registration_tokens`/i', fn (): string => $this->raced(fn () => Member::factory()->create(['email' => 'admin@example.com'])));
        $result = app(IssueRegistrationToken::class)->unmailedLink('admin@example.com', RegistrationTokenSource::AdminRoleInvite, function (CarbonInterface $createdAt): void {
            AdminRoleInvite::upsert([['email' => 'admin@example.com', 'expires_at' => $createdAt->copy()->addDay()->getTimestamp(), 'created_at' => $createdAt]], ['email'], ['expires_at', 'created_at']);
        });

        $this->assertSame(['done'], $outcomes->getArrayCopy());
        $this->assertSame(IssueResult::AlreadyMember, $result);
        $this->assertDatabaseCount('registration_tokens', 0);
        $this->assertDatabaseCount('admin_role_invites', 0);
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
