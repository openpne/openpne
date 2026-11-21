<?php

namespace Tests\Feature\Group;

use App\Features\Group\Actions\AcceptAdminTransfer;
use App\Features\Group\Actions\DeleteGroup;
use App\Features\Group\BoardReactionSurface;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupRole;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\ReactionVocabulary;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\OpensSecondConnection;
use Tests\TestCase;

/**
 * Each race is fired on a second connection from inside the delete. A lock wait there is caught
 * and reported, since letting it escape would roll the delete back and pass.
 */
class DeleteGroupLockTest extends TestCase
{
    use AssertsGroupFailure, MigratesUpgradeTargetsOnce, OpensSecondConnection;

    private const LOCK_WAIT = 1205;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Two connections need a server; sqlite :memory: has one.');
        }

        $this->openSecondConnection();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->closeSecondConnection();
        }

        parent::tearDown();
    }

    public function test_a_transfer_accepted_while_the_admin_deletes_waits_on_the_group_row(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);
        $nominee = $this->joined($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $outcomes = $this->afterTheRoleRead(fn (): string => $this->raced(fn () => app(AcceptAdminTransfer::class)($nominee, $group)));
        app(DeleteGroup::class)($admin, $group);

        $this->assertCount(1, $outcomes, 'the race did not run exactly once');
        $this->assertWaitedOn('groups', $outcomes[0]);
        $this->assertDatabaseMissing('groups', ['id' => $group->getKey()]);
    }

    public function test_a_transfer_committed_just_before_the_delete_takes_the_group_row_refuses_the_delete(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);
        $nominee = $this->joined($group, GroupRole::Member);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $outcomes = $this->beforeTheGroupLock(fn (): string => $this->raced(fn () => app(AcceptAdminTransfer::class)($nominee, $group)));

        $this->assertFailsWith(GroupActionFailure::NotAdmin, fn () => app(DeleteGroup::class)($admin, $group));
        $this->assertSame(['done'], $outcomes->getArrayCopy());
        $this->assertDatabaseHas('groups', ['id' => $group->getKey()]);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $nominee->getKey(), 'role' => GroupRole::Admin->value]);
    }

    public function test_a_reaction_arriving_at_the_role_check_waits_on_the_topic_and_leaves_no_orphan(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);
        $reactor = $this->joined($group, GroupRole::Member);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        $outcomes = $this->afterTheRoleRead(fn (): string => $this->raced(fn () => app(AddReaction::class)($reactor, $topic, ReactionVocabulary::all()[0], new BoardReactionSurface)));
        app(DeleteGroup::class)($admin, $group);

        $this->assertCount(1, $outcomes, 'the race did not run exactly once');
        $this->assertWaitedOn('group_topics', $outcomes[0]);
        $this->assertDatabaseMissing('groups', ['id' => $group->getKey()]);
        $this->assertDatabaseCount('reactions', 0);
    }

    private function joined(Group $group, GroupRole $role): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => $role]);

        return $member;
    }

    /**
     * Runs $race once, on the first `group_members` read of the default connection; the second
     * connection reads that table too and is not the trigger.
     *
     * @param  callable(): string  $race
     * @return \ArrayObject<int, string>
     */
    private function afterTheRoleRead(callable $race): \ArrayObject
    {
        $outcomes = new \ArrayObject;
        $connection = DB::getDefaultConnection();

        DB::listen(function (QueryExecuted $query) use ($race, $connection, $outcomes): void {
            if ($query->connectionName !== $connection || ! str_contains($query->sql, '`group_members`') || count($outcomes) > 0) {
                return;
            }
            $outcomes[] = 'running';
            $outcomes[0] = $race();
        });

        return $outcomes;
    }

    /**
     * Runs $race once, just before the default connection's locking read of the group row, so
     * whatever the race commits is already there when the delete takes its snapshot.
     *
     * @param  callable(): string  $race
     * @return \ArrayObject<int, string>
     */
    private function beforeTheGroupLock(callable $race): \ArrayObject
    {
        $outcomes = new \ArrayObject;
        $connection = DB::getDefaultConnection();

        DB::beforeExecuting(function (string $sql, array $bindings, Connection $on) use ($race, $connection, $outcomes): void {
            if ($on->getName() !== $connection || ! preg_match('/from `groups`.*for update/is', $sql) || count($outcomes) > 0) {
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

    private function assertWaitedOn(string $table, string $outcome): void
    {
        $this->assertStringStartsWith('waited:', $outcome, "the race did not wait ({$outcome})");
        $sql = substr($outcome, strlen('waited:'));
        $this->assertMatchesRegularExpression("/from `{$table}`.*for update/is", $sql, "waited on the wrong statement: {$sql}");
    }
}
