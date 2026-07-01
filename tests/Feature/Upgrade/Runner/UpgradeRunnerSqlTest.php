<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\Member;
use App\Models\UpgradeState;
use App\Upgrade\Column;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises the runner's orchestration end-to-end: a single member_relationship source decomposes
 * into three targets (the three relation steps), letting one fixture cover run order, checkpointing,
 * resume, dry-run, force-restart, and the not-runnable skip. Members are created directly by the
 * factory, so the steps' only source table is member_relationship.
 *
 * DatabaseMigrations (not RefreshDatabase): creating the source table is DDL and auto-commits.
 */
class UpgradeRunnerSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The runner executes INSERT...SELECT on MySQL.');
        }

        DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        DB::statement(SourceSchema::default()->createStatement('member_relationship', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `member_relationship`');
        }

        parent::tearDown();
    }

    public function test_runs_steps_in_order_and_checkpoints_each(): void
    {
        $this->seedGraph();

        $this->assertTrue($this->runner($this->relationSteps())->run(new RunOptions));

        $this->assertDatabaseCount('friendships', 2);
        $this->assertDatabaseCount('friend_requests', 1);
        $this->assertDatabaseCount('member_blocks', 1);

        foreach (['FriendshipUpgrade', 'FriendRequestUpgrade', 'MemberBlockUpgrade'] as $key) {
            $this->assertDatabaseHas('openpne4_upgrade_state', ['step_key' => $key, 'status' => UpgradeState::STATUS_COMPLETED]);
        }
        $this->assertSame(2, UpgradeState::query()->where('step_key', 'FriendshipUpgrade')->value('rows_affected'));
    }

    public function test_resume_skips_completed_steps(): void
    {
        $this->seedGraph();
        $this->runner($this->relationSteps())->run(new RunOptions);

        // Simulate a crash after Friendship/MemberBlock committed but FriendRequest did not.
        UpgradeState::query()->where('step_key', 'FriendRequestUpgrade')->delete();
        DB::table('friend_requests')->delete();

        // Re-running a completed step would PK-collide on verbatim ids, so success proves the
        // completed steps were skipped and only the incomplete one was redone.
        $this->assertTrue($this->runner($this->relationSteps())->run(new RunOptions));
        $this->assertDatabaseCount('friend_requests', 1);
        $this->assertDatabaseCount('friendships', 2);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedGraph();

        $lines = [];
        $ok = $this->runner($this->relationSteps())->run(new RunOptions(dryRun: true), function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertTrue($ok);
        $this->assertStringContainsString('INSERT INTO `friendships`', implode("\n", $lines));
        $this->assertDatabaseCount('friendships', 0);
        $this->assertDatabaseCount('openpne4_upgrade_state', 0);
    }

    public function test_force_restart_clears_targets_and_reruns(): void
    {
        $this->seedGraph();
        $runner = $this->runner($this->relationSteps());
        $this->assertTrue($runner->run(new RunOptions));
        $this->assertDatabaseCount('friendships', 2);

        // --force-restart clears the targets + checkpoints inside run() (after the preflight passes),
        // then re-runs without colliding on the verbatim ids it re-inserts.
        $this->assertTrue($runner->run(new RunOptions(forceRestart: true)));
        $this->assertDatabaseCount('friendships', 2);
        $this->assertDatabaseHas('openpne4_upgrade_state', ['step_key' => 'FriendshipUpgrade', 'status' => UpgradeState::STATUS_COMPLETED]);
    }

    public function test_a_not_runnable_step_is_skipped(): void
    {
        $this->seedGraph();

        $pending = new class extends UpgradeStep
        {
            protected string $source = 'member_relationship';

            protected string $target = 'friendships';

            public function columns(): array
            {
                return ['id' => Column::source('id')];
            }

            public function pendingTargets(): array
            {
                return ['unresolved' => 'no source yet'];
            }
        };

        $lines = [];
        $ok = $this->runner([$pending, new FriendshipUpgrade])->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        // The pending step is skipped (never compiled, no LogicException) and the real step still runs.
        $this->assertTrue($ok);
        $this->assertStringContainsString('not runnable', implode("\n", $lines));
        $this->assertDatabaseCount('friendships', 2);
    }

    public function test_the_command_runs_the_runner_on_mysql(): void
    {
        $this->seedGraph();
        $this->app->instance(UpgradeRunner::class, $this->runner($this->relationSteps()));

        $this->artisan('openpne:upgrade-from-3')
            ->expectsOutputToContain('DONE FriendshipUpgrade')
            ->assertSuccessful();

        $this->assertDatabaseCount('friendships', 2);
        $this->assertDatabaseHas('openpne4_upgrade_state', ['step_key' => 'FriendshipUpgrade', 'status' => UpgradeState::STATUS_COMPLETED]);
    }

    /** @param list<UpgradeStep> $steps */
    private function runner(array $steps): UpgradeRunner
    {
        return new UpgradeRunner(new InsertSelectCompiler, $steps);
    }

    /** @return list<UpgradeStep> */
    private function relationSteps(): array
    {
        return [new FriendshipUpgrade, new FriendRequestUpgrade, new MemberBlockUpgrade];
    }

    private function seedGraph(): void
    {
        [$a, $b, $c, $d, $e, $f] = Member::factory()->count(6)->create()->all();
        $this->seedRelationship($a, $b, ['is_friend' => 1]);
        $this->seedRelationship($b, $a, ['is_friend' => 1]);
        $this->seedRelationship($c, $d, ['is_friend_pre' => 1]);
        $this->seedRelationship($e, $f, ['is_access_block' => 1]);
    }

    private function seedRelationship(Member $from, Member $to, array $flags): void
    {
        DB::table('member_relationship')->insert(array_merge([
            'member_id_from' => $from->id,
            'member_id_to' => $to->id,
            'is_friend' => null,
            'is_friend_pre' => null,
            'is_access_block' => null,
            'created_at' => '2018-01-02 03:04:05',
            'updated_at' => '2018-01-02 03:04:05',
        ], $flags));
    }
}
