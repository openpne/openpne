<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\StepRegistry;
use App\Upgrade\Steps\CommunityEventPluginFeatureUpgrade;
use App\Upgrade\Steps\FriendFeatureUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\Steps\PluginFeatureUpgrade;
use App\Upgrade\Steps\SnsSettingUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Driver-agnostic runner/command behavior (the INSERT...SELECT execution is covered on the MySQL
 * lane by UpgradeRunnerSqlTest).
 */
class UpgradeRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_requires_mysql(): void
    {
        // Asserts the non-MySQL guard, so it only applies off the mysql lane.
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('The driver guard only fires on a non-MySQL connection.');
        }

        $this->artisan('openpne:upgrade-from-3')
            ->expectsOutputToContain('requires MySQL')
            ->assertFailed();
    }

    public function test_an_invalid_source_prefix_is_rejected(): void
    {
        $this->artisan('openpne:upgrade-from-3', ['--source-prefix' => 'bad-prefix!'])
            ->expectsOutputToContain('--source-prefix must match')
            ->assertFailed();
    }

    public function test_an_invalid_source_database_is_rejected(): void
    {
        $this->artisan('openpne:upgrade-from-3', ['--source-database' => 'bad db'])
            ->expectsOutputToContain('--source-database must match')
            ->assertFailed();
    }

    public function test_upgrade_state_round_trips_metadata_and_casts(): void
    {
        $state = UpgradeState::create([
            'step_key' => 'FileUpgrade',
            'status' => UpgradeState::STATUS_COMPLETED,
            'rows_affected' => 42,
            'metadata' => ['max_file_id' => 1000],
        ]);

        $fresh = $state->fresh();

        $this->assertSame(['max_file_id' => 1000], $fresh->metadata);
        $this->assertSame(42, $fresh->rows_affected);
        $this->assertSame(UpgradeState::STATUS_COMPLETED, $fresh->status);
    }

    public function test_a_fresh_state_is_stamped_with_this_versions_naming_epoch(): void
    {
        $runner = new UpgradeRunner(new InsertSelectCompiler, []);

        $this->assertTrue($runner->claimNamingEpoch());
        $this->assertSame(['epoch' => UpgradeRunner::NAMING_EPOCH], UpgradeState::query()->where('step_key', 'naming_epoch')->value('metadata'));

        // Claiming again on the state this version wrote is the resume case, and passes.
        $this->assertTrue($runner->claimNamingEpoch());
    }

    public function test_state_from_another_naming_epoch_aborts_the_run(): void
    {
        UpgradeState::create([
            'step_key' => 'naming_epoch',
            'status' => UpgradeState::STATUS_COMPLETED,
            'metadata' => ['epoch' => UpgradeRunner::NAMING_EPOCH - 1],
        ]);

        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, []))->claimNamingEpoch(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertFalse($ok);
        $this->assertStringContainsString('--force-restart', implode("\n", $lines));
    }

    public function test_a_marker_without_a_readable_epoch_aborts_too(): void
    {
        UpgradeState::create(['step_key' => 'naming_epoch', 'status' => UpgradeState::STATUS_COMPLETED]);

        $this->assertFalse((new UpgradeRunner(new InsertSelectCompiler, []))->claimNamingEpoch());
    }

    public function test_a_reset_clears_the_marker_so_the_next_run_can_claim_it(): void
    {
        $runner = new UpgradeRunner(new InsertSelectCompiler, []);
        UpgradeState::create([
            'step_key' => 'naming_epoch',
            'status' => UpgradeState::STATUS_COMPLETED,
            'metadata' => ['epoch' => UpgradeRunner::NAMING_EPOCH - 1],
        ]);
        $this->assertFalse($runner->claimNamingEpoch());

        $runner->reset();

        $this->assertTrue($runner->claimNamingEpoch());
    }

    public function test_the_epoch_marker_is_not_walked_as_a_step(): void
    {
        // step_key doubles as the marker's row key, so a registry that ever produced this basename
        // would collide with it.
        $this->assertNotContains('naming_epoch', array_map(
            static fn (object $step): string => class_basename($step),
            StepRegistry::all(),
        ));
    }

    public function test_target_tables_are_distinct_in_reverse_run_order(): void
    {
        // The FK-safe delete order for --force-restart: a pure function over the step list (no DB).
        $runner = new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade, new FriendshipUpgrade, new MemberBlockUpgrade]);

        $this->assertSame(['member_blocks', 'friendships', 'members'], $runner->targetTables());
    }

    public function test_a_target_table_several_steps_share_is_reset_once(): void
    {
        // The sns_settings steps all write one table; --force-restart clears it once, not per step.
        $runner = new UpgradeRunner(new InsertSelectCompiler, [
            new SnsSettingUpgrade, new PluginFeatureUpgrade, new CommunityEventPluginFeatureUpgrade, new FriendFeatureUpgrade,
        ]);

        $this->assertSame(['sns_settings'], $runner->targetTables());
    }
}
