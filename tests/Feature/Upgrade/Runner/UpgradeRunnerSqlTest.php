<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Mail\Template\MailTemplateService;
use App\Models\Member;
use App\Models\UpgradeState;
use App\Services\GadgetService;
use App\Services\NavigationService;
use App\Services\SnsSettingService;
use App\Services\TermService;
use App\Upgrade\Column;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SeedsSourceMembers;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/**
 * One member_relationship fixture decomposing into the three relation steps, so run order,
 * checkpointing, resume, dry run, force-restart and the not-runnable skip share it; members come
 * from the factory, so the only source table is member_relationship.
 */
class UpgradeRunnerSqlTest extends UpgradeSqlTestCase
{
    use SeedsSourceMembers;

    protected function sourceTables(): array
    {
        return ['member', 'member_relationship'];
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

    public function test_a_run_stamps_the_naming_epoch_and_refuses_state_from_another_one(): void
    {
        $this->seedGraph();
        $runner = $this->runner($this->relationSteps());
        $this->assertTrue($runner->run(new RunOptions));
        $this->assertSame(['epoch' => UpgradeRunner::NAMING_EPOCH], UpgradeState::query()->where('step_key', 'naming_epoch')->value('metadata'));

        UpgradeState::query()->where('step_key', 'naming_epoch')->update(['metadata' => json_encode(['epoch' => UpgradeRunner::NAMING_EPOCH - 1])]);

        $lines = [];
        $ok = $runner->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertFalse($ok);
        $this->assertStringContainsString('naming epoch', implode("\n", $lines));

        // --force-restart clears the state, so the same command starts over cleanly.
        $this->assertTrue($runner->run(new RunOptions(forceRestart: true)));
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

    public function test_each_step_prints_a_start_line_and_a_timed_done_line(): void
    {
        $this->seedGraph();

        $lines = [];
        $this->assertTrue($this->runner($this->relationSteps())->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        $run = array_search('RUN FriendshipUpgrade', $lines, true);
        $this->assertNotFalse($run);
        $this->assertMatchesRegularExpression('/^DONE FriendshipUpgrade: 2 rows \(\d+\.\ds\)$/', $lines[$run + 1]);
        $this->assertMatchesRegularExpression('/^Finished in \d+\.\ds$/', end($lines));
    }

    public function test_the_json_report_replaces_the_human_lines(): void
    {
        $this->seedGraph();
        $this->app->instance(UpgradeRunner::class, $this->runner($this->relationSteps()));

        $report = $this->jsonReport(['--json' => true]);

        $this->assertStringNotContainsString('DONE FriendshipUpgrade', $this->lastOutput);
        $this->assertSame('ok', $report['outcome']);
        $this->assertFalse($report['resumed']);
        $this->assertSame('FriendshipUpgrade', $report['steps'][0]['key']);
        $this->assertSame('done', $report['steps'][0]['status']);
        $this->assertSame(2, $report['steps'][0]['rows']);
        $this->assertIsInt($report['steps'][0]['elapsed_ms']);
        $this->assertSame(DB::connection()->getDatabaseName(), $report['site']);
        $this->assertNull($report['release']);

        $this->assertTrue($this->jsonReport(['--json' => true])['resumed'], 'the second run skipped the completed steps');
    }

    public function test_a_failed_step_is_one_fail_line_without_the_sql(): void
    {
        $this->seedGraph();
        Exceptions::fake();
        $this->app->instance(UpgradeRunner::class, $this->runner([new FriendshipUpgrade, $this->brokenStep()]));

        $report = $this->jsonReport(['--json' => true], expectFailure: true);

        Exceptions::assertReported(QueryException::class);

        $this->assertSame('failed_at_step', $report['outcome']);
        $this->assertSame('BrokenMemberBlockUpgrade', $report['failed_step']);
        $failed = $report['steps'][1];
        $this->assertSame('failed', $failed['status']);
        $this->assertStringStartsWith('SQLSTATE[42S02]', $failed['error']);
        $this->assertStringNotContainsString('INSERT INTO', $failed['error']);
        $this->assertIsInt($failed['elapsed_ms']);
        $this->assertStringNotContainsString('INSERT INTO', (string) UpgradeState::query()->where('step_key', 'BrokenMemberBlockUpgrade')->value('error'));

        $lines = [];
        $this->runner([new FriendshipUpgrade, $this->brokenStep()])->run(new RunOptions, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $fails = array_values(array_filter($lines, fn (string $l): bool => str_starts_with($l, 'FAIL')));
        $this->assertCount(1, $fails);
        $this->assertStringNotContainsString("\n", $fails[0]);
    }

    public function test_verbose_rethrows_the_failure_after_its_line_and_checkpoint(): void
    {
        $this->seedGraph();
        $lines = [];

        try {
            $this->runner([new FriendshipUpgrade, $this->brokenStep()])->run(new RunOptions(verbose: true), function (string $line) use (&$lines): void {
                $lines[] = $line;
            });
            $this->fail('the step failure should have been rethrown');
        } catch (QueryException) {
            $this->assertStringContainsString('FAIL BrokenMemberBlockUpgrade:', implode("\n", $lines));
            $this->assertDatabaseHas('openpne4_upgrade_state', ['step_key' => 'BrokenMemberBlockUpgrade', 'status' => UpgradeState::STATUS_FAILED]);
        }
    }

    public function test_a_missing_state_table_is_a_precheck_error_in_the_json_too(): void
    {
        $this->seedGraph();
        // The lane's trait re-migrates the targets when this sentinel is missing, so the drop heals.
        Schema::drop('openpne4_upgrade_state');

        $report = $this->jsonReport(['--json' => true], expectFailure: true);

        $this->assertSame('precheck_error', $report['outcome']);
        $this->assertStringContainsString('openpne4_upgrade_state', $report['errors'][0]);
        $this->assertSame([], $report['steps']);
    }

    public function test_verbose_json_prints_the_report_before_the_rethrow(): void
    {
        $this->seedGraph();
        $this->app->instance(UpgradeRunner::class, $this->runner([new FriendshipUpgrade, $this->brokenStep()]));

        try {
            Artisan::call('openpne:upgrade-from-3', ['--json' => true, '-v' => true]);
            $this->fail('the step failure should have been rethrown under -v');
        } catch (QueryException) {
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('failed_at_step', $report['outcome']);
            $this->assertSame('BrokenMemberBlockUpgrade', $report['failed_step']);
        }
    }

    public function test_a_failure_outside_any_step_is_the_runs_and_still_yields_json(): void
    {
        $this->seedGraph();
        Exceptions::fake();
        // --force-restart DELETEs every target before any step runs, so the broken step's missing
        // table makes reset() throw with no step started.
        $this->app->instance(UpgradeRunner::class, $this->runner([new FriendshipUpgrade, $this->brokenStep()]));

        $report = $this->jsonReport(['--json' => true, '--force-restart' => true], expectFailure: true);

        Exceptions::assertReported(QueryException::class);
        $this->assertNotNull($report['finished_at']);
        $this->assertSame('failed_at_step', $report['outcome']);
        $this->assertSame('run', $report['failed_step']);
        $this->assertSame(['run'], array_column($report['steps'], 'key'));
        $this->assertStringStartsWith('SQLSTATE[42S02]', $report['steps'][0]['error']);
        $this->assertStringNotContainsString('DELETE', $report['steps'][0]['error']);
    }

    private function brokenStep(): UpgradeStep
    {
        return new BrokenMemberBlockUpgrade;
    }

    private string $lastOutput = '';

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function jsonReport(array $parameters, bool $expectFailure = false): array
    {
        $exit = Artisan::call('openpne:upgrade-from-3', $parameters);
        $this->lastOutput = Artisan::output();
        $this->assertSame($expectFailure ? 1 : 0, $exit, $this->lastOutput);

        return json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_a_run_forgets_every_cached_map_the_steps_wrote(): void
    {
        $this->seedGraph();
        $this->expectCachesForgotten();

        $this->assertTrue($this->runner($this->relationSteps())->run(new RunOptions));
    }

    public function test_a_failed_run_forgets_the_cached_maps_too(): void
    {
        // The second step writes to a table that does not exist, so the walk stops after the first
        // committed its rows, which a cache warmed before the cutover would otherwise keep hiding.
        $this->seedGraph();
        $broken = new class extends MemberBlockUpgrade
        {
            public function targetTable(): string
            {
                return 'no_such_table';
            }
        };
        $this->expectCachesForgotten();

        $this->assertFalse($this->runner([new FriendshipUpgrade, $broken])->run(new RunOptions));
        $this->assertDatabaseCount('friendships', 2);
    }

    /** After the walk, not before it: the clear is checked against the rows the first step lands. */
    private function expectCachesForgotten(): void
    {
        foreach ([TermService::class, SnsSettingService::class, NavigationService::class, GadgetService::class, MailTemplateService::class] as $service) {
            $this->partialMock($service, function ($mock): void {
                $mock->shouldReceive('clearCache')->atLeast()->once()->andReturnUsing(function (): void {
                    $this->assertSame(2, DB::table('friendships')->count(), 'a cache was cleared before the walk landed its rows');
                });
            });
        }
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
        [$a, $b, $c, $d, $e, $f] = $this->activeMembers(6);
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

/** Writes to a table that does not exist, so its INSERT fails after the earlier step committed; a named class so its checkpoint key is stable. */
final class BrokenMemberBlockUpgrade extends MemberBlockUpgrade
{
    public function targetTable(): string
    {
        return 'no_such_table';
    }
}
