<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Driver-agnostic runner/command behaviour (the INSERT...SELECT execution is covered on the MySQL
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

    public function test_target_tables_are_distinct_in_reverse_run_order(): void
    {
        // The FK-safe delete order for --force-restart: a pure function over the step list (no DB).
        $runner = new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade, new FriendshipUpgrade, new MemberBlockUpgrade]);

        $this->assertSame(['member_blocks', 'friendships', 'members'], $runner->targetTables());
    }
}
