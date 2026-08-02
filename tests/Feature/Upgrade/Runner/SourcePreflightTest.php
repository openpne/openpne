<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\Member;
use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\SourcePreflight;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityMemberUpgrade;
use App\Upgrade\Steps\CommunityUpgrade;
use App\Upgrade\Steps\DiaryImageUpgrade;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\Steps\MemberPreferenceUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The source preflight: an absent optional plugin group is created empty so its steps no-op; a
 * missing CORE table, a missing consumed column, or a partial plugin group aborts before any write;
 * a KV config name outside the recognised set is reported with its row count and migrated past.
 */
class SourcePreflightTest extends TestCase
{
    use DatabaseMigrations;

    private const SOURCE_TABLES = ['diary', 'diary_image', 'community_member', 'community_member_position', 'member_relationship',
        'member_config', 'community', 'community_config', 'community_category'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The preflight introspects information_schema and the runner executes on MySQL.');
        }

        $this->dropSourceTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceTables();
        }

        parent::tearDown();
    }

    public function test_absent_optional_plugin_group_is_created_empty_and_dropped(): void
    {
        // opDiary not installed: `diary` is absent. The step no-ops against an empty ensure-existed
        // table, and the table is dropped afterwards (the source namespace is left clean).
        [$ok, $output] = $this->runSteps([new DiaryUpgrade]);

        $this->assertTrue($ok);
        $this->assertStringContainsString('`diary` absent', $output);
        $this->assertStringContainsString('DONE DiaryUpgrade: 0 rows', $output);
        $this->assertDatabaseCount('diaries', 0);
        $this->assertFalse($this->sourceExists('diary'), 'the ensure-existed table should be dropped');
    }

    public function test_missing_consumed_column_aborts_before_any_write(): void
    {
        $this->createSource('member_relationship');
        DB::statement('ALTER TABLE `member_relationship` DROP COLUMN `is_access_block`'); // MemberBlockUpgrade reads it

        [$ok, $output] = $this->runSteps([new FriendshipUpgrade, new FriendRequestUpgrade, new MemberBlockUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::missingColumnMessage('member_relationship', 'is_access_block'), $output);
        $this->assertDatabaseCount('friendships', 0);
        $this->assertDatabaseCount('member_blocks', 0);
        $this->assertDatabaseCount('openpne4_upgrade_state', 0);
    }

    public function test_missing_core_table_aborts(): void
    {
        // community_member_position is CORE (created by OpenPNE 3 migration 3.3.1), so its absence is a
        // broken/old source, not an uninstalled plugin.
        $this->createSource('community_member');

        [$ok, $output] = $this->runSteps([new CommunityMemberUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::missingTableMessage('community_member_position'), $output);
        $this->assertDatabaseCount('community_members', 0);
        $this->assertDatabaseCount('openpne4_upgrade_state', 0);
    }

    public function test_partial_plugin_group_aborts(): void
    {
        // opDiary present (`diary`) but missing `diary_image` — an old/corrupt plugin.
        $this->createSource('diary');

        [$ok, $output] = $this->runSteps([new DiaryUpgrade, new DiaryImageUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::partialPluginMessage('opDiaryPlugin', '1.1.1', ['diary_image']), $output);
        $this->assertDatabaseCount('openpne4_upgrade_state', 0);
    }

    public function test_a_not_read_table_absence_does_not_partial_abort(): void
    {
        // Same `diary` present / `diary_image` absent, but the run reads only `diary` (no DiaryImageUpgrade),
        // so `diary_image` is outside the read set and must not trigger a partial abort.
        $this->createSource('diary');

        [$ok] = $this->runSteps([new DiaryUpgrade]);

        $this->assertTrue($ok);
        $this->assertDatabaseCount('diaries', 0);
    }

    public function test_dry_run_aborts_on_errors_and_writes_nothing(): void
    {
        $this->createSource('community_member'); // community_member_position absent → error

        [$ok, $output] = $this->runSteps([new CommunityMemberUpgrade], new RunOptions(dryRun: true));

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::missingTableMessage('community_member_position'), $output);
        $this->assertDatabaseCount('openpne4_upgrade_state', 0);
    }

    public function test_dry_run_reports_but_creates_nothing(): void
    {
        [$ok, $output] = $this->runSteps([new DiaryUpgrade], new RunOptions(dryRun: true));

        $this->assertTrue($ok);
        $this->assertStringContainsString('would create empty source table `diary`', $output);
        $this->assertStringContainsString('PLAN DiaryUpgrade:', $output);
        $this->assertFalse($this->sourceExists('diary'), 'dry-run must not create the table');
        $this->assertDatabaseCount('openpne4_upgrade_state', 0);
    }

    public function test_force_restart_with_a_bad_source_keeps_existing_data(): void
    {
        // Existing target rows + a checkpoint from an earlier run; --force-restart would normally clear both.
        [$a, $b] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert(['member_id' => $a->id, 'friend_id' => $b->id]);
        UpgradeState::create(['step_key' => 'FriendshipUpgrade', 'status' => UpgradeState::STATUS_COMPLETED]);

        $this->createSource('member_relationship');
        DB::statement('ALTER TABLE `member_relationship` DROP COLUMN `is_access_block`'); // preflight will reject

        [$ok] = $this->runSteps(
            [new FriendshipUpgrade, new FriendRequestUpgrade, new MemberBlockUpgrade],
            new RunOptions(forceRestart: true),
        );

        $this->assertFalse($ok);
        // reset() (which clears the targets and the checkpoints) must not run before the preflight abort.
        $this->assertDatabaseCount('friendships', 1);
        $this->assertDatabaseCount('openpne4_upgrade_state', 1);
    }

    public function test_unrecognised_config_names_are_reported_with_row_counts(): void
    {
        $this->createSource('member_config');
        $this->insertConfig([
            ['member_id' => 1, 'name' => 'op_custom_plugin_flag', 'value' => '1'],
            ['member_id' => 2, 'name' => 'op_custom_plugin_flag', 'value' => '0'],
            ['member_id' => 1, 'name' => 'op_another_custom', 'value' => 'x'],
            ['member_id' => 1, 'name' => 'diary_public_flag', 'value' => '1'],
            ['member_id' => 1, 'name' => 'is_send_pc_diaryReplyPost_mail', 'value' => '0'],
            ['member_id' => 1, 'name' => 'op_screen_name', 'value' => 'someone'],
        ]);

        [$ok, $output] = $this->runSteps([new MemberPreferenceUpgrade], new RunOptions(dryRun: true));

        $this->assertTrue($ok, 'an unrecognised name is a warning, not an abort');
        $this->assertStringContainsString(
            SourcePreflight::unknownConfigNameMessage('member_config', 'op_custom_plugin_flag', 2),
            $output,
        );
        $this->assertStringContainsString(
            SourcePreflight::unknownConfigNameMessage('member_config', 'op_another_custom', 1),
            $output,
        );
        // A migrated preference, a registry-derived notification key, and a deliberately-dropped
        // name are all recognised, so none of the three is reported.
        $this->assertStringNotContainsString('named `diary_public_flag`', $output);
        $this->assertStringNotContainsString('named `is_send_pc_diaryReplyPost_mail`', $output);
        $this->assertStringNotContainsString('named `op_screen_name`', $output);
    }

    public function test_a_source_without_the_name_column_aborts_instead_of_failing_the_scan(): void
    {
        // The unknown-name scan reads `name`, the very column the structural check guards: a source
        // missing it must reach the abort, not blow up inside the scan.
        $this->createSource('member_config');
        DB::statement('ALTER TABLE `member_config` DROP COLUMN `name`');

        [$ok, $output] = $this->runSteps([new MemberPreferenceUpgrade]);

        $this->assertFalse($ok);
        $this->assertStringContainsString(SourcePreflight::missingColumnMessage('member_config', 'name'), $output);
        $this->assertStringNotContainsString('does not recognise', $output);
    }

    public function test_unrecognised_community_config_names_are_reported(): void
    {
        foreach (['community', 'community_config', 'community_category', 'community_member_position'] as $table) {
            $this->createSource($table); // CommunityUpgrade reads all four
        }
        DB::table('community_config')->insert([
            ['community_id' => 1, 'name' => 'op_custom_community_flag', 'value' => '1',
                'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
            ['community_id' => 1, 'name' => 'register_policy', 'value' => 'open',
                'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
        ]);

        [$ok, $output] = $this->runSteps([new CommunityUpgrade], new RunOptions(dryRun: true));

        $this->assertTrue($ok);
        $this->assertStringContainsString(
            SourcePreflight::unknownConfigNameMessage('community_config', 'op_custom_community_flag', 1),
            $output,
        );
        $this->assertStringNotContainsString('named `register_policy`', $output);
    }

    public function test_a_source_holding_only_recognised_names_reports_nothing(): void
    {
        $this->createSource('member_config');
        $this->insertConfig([['member_id' => 1, 'name' => 'age_public_flag', 'value' => '2']]);

        [$ok, $output] = $this->runSteps([new MemberPreferenceUpgrade], new RunOptions(dryRun: true));

        $this->assertTrue($ok);
        $this->assertStringNotContainsString('does not recognise', $output);
    }

    /** @param list<array{member_id: int, name: string, value: string}> $rows */
    private function insertConfig(array $rows): void
    {
        DB::table('member_config')->insert(array_map(static fn (array $row): array => $row + [
            'name_value_hash' => md5($row['name'].$row['value']),
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ], $rows));
    }

    /**
     * @param  list<UpgradeStep>  $steps
     * @return array{0: bool, 1: string}
     */
    private function runSteps(array $steps, ?RunOptions $options = null): array
    {
        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, $steps))->run(
            $options ?? new RunOptions,
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        return [$ok, implode("\n", $lines)];
    }

    private function createSource(string $table): void
    {
        DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
    }

    private function sourceExists(string $table): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ? limit 1',
            [DB::connection()->getDatabaseName(), $table],
        ) !== null;
    }

    private function dropSourceTables(): void
    {
        foreach (self::SOURCE_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
