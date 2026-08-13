<?php

namespace Tests\Feature\Upgrade\Verify;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityEventPluginFeatureUpgrade;
use App\Upgrade\Steps\FriendFeatureUpgrade;
use App\Upgrade\Steps\PluginFeatureUpgrade;
use App\Upgrade\Steps\SnsSettingUpgrade;
use App\Upgrade\UpgradeStep;
use App\Upgrade\Verify\UpgradeVerifier;
use App\Upgrade\Verify\VerifyReport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * Check A over `sns_settings`, the one target several steps write: each step's count must see only
 * the rows it owns (UpgradeStep::targetFilter). The table also collects rows no step wrote — the
 * runner's post-walk surface_mode stamp, and the enabled-by-default rows the admin Features page
 * materializes on its first save — and none of them may read as target drift.
 */
class VerifierSharedTargetTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('verify re-counts the OpenPNE 3 source DDL on MySQL.');
        }

        foreach (['plugin', 'sns_config'] as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }

        $this->seedSource();
        (new UpgradeRunner(new InsertSelectCompiler, $this->settingSteps()))->run(new RunOptions);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `plugin`');
            DB::statement('DROP TABLE IF EXISTS `sns_config`');
        }

        parent::tearDown();
    }

    public function test_a_clean_migration_passes_on_a_mixed_settings_table(): void
    {
        // The upgrade wrote two disabled units, the runner stamped surface_mode, and an operator then
        // saved the Features page — materializing a '1' row for every unit still enabled.
        $this->materializeEnabledFeatureRows();

        [$report, $out] = $this->verify();

        $this->assertFalse($report->failed(), $out);
        $this->assertStringContainsString('PASS SnsSettingUpgrade', $out);
        $this->assertStringContainsString('PASS PluginFeatureUpgrade', $out);
        $this->assertStringContainsString('PASS CommunityEventPluginFeatureUpgrade', $out);
        $this->assertStringContainsString('PASS FriendFeatureUpgrade', $out);
        $this->assertDatabaseHas('sns_settings', ['key' => 'surface_mode', 'value' => 'classic_default']);
    }

    public function test_a_force_restart_rerun_stays_clean(): void
    {
        // Reset clears the shared target once (the steps' target tables are deduped), so re-inserting
        // the same keys cannot collide on the primary key and the post-walk stamp lands again.
        $this->assertTrue((new UpgradeRunner(new InsertSelectCompiler, $this->settingSteps()))->run(new RunOptions(forceRestart: true)));

        [$report, $out] = $this->verify();

        $this->assertFalse($report->failed(), $out);
        $this->assertDatabaseHas('sns_settings', ['key' => 'surface_mode', 'value' => 'classic_default']);
    }

    public function test_scoping_still_catches_a_lost_row(): void
    {
        DB::table('sns_settings')->where('key', 'feature_diary_enabled')->delete();

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL PluginFeatureUpgrade', $out);
        $this->assertStringContainsString('PASS SnsSettingUpgrade', $out);
    }

    /** @return array{0: VerifyReport, 1: string} */
    private function verify(): array
    {
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, $this->settingSteps()))
            ->verify(new RunOptions, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        return [$report, implode("\n", $lines)];
    }

    /** @return list<UpgradeStep> */
    private function settingSteps(): array
    {
        return [new SnsSettingUpgrade, new PluginFeatureUpgrade, new CommunityEventPluginFeatureUpgrade, new FriendFeatureUpgrade];
    }

    private function seedSource(): void
    {
        DB::table('sns_config')->insert([
            ['name' => 'sns_name', 'value' => 'My SNS'],
            ['name' => 'enable_friend_link', 'value' => '0'],
            ['name' => 'is_use_captcha', 'value' => '0'], // not migrated (security key)
        ]);

        foreach ([['opDiaryPlugin', 0], ['opCommunityTopicPlugin', 0], ['opMessagePlugin', 1]] as [$name, $isEnabled]) {
            DB::table('plugin')->insert([
                'name' => $name,
                'is_enabled' => $isEnabled,
                'created_at' => '2018-01-02 03:04:05',
                'updated_at' => '2018-01-02 03:04:05',
            ]);
        }
    }

    /** What the admin Features page does on its first save: store every key of the group, enabled ones too. */
    private function materializeEnabledFeatureRows(): void
    {
        foreach (['feature_direct_message_enabled', 'feature_timeline_enabled', 'feature_community_enabled'] as $key) {
            DB::table('sns_settings')->insert(['key' => $key, 'value' => '1']);
        }
    }
}
