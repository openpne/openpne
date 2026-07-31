<?php

namespace Tests\Feature\Upgrade\Feature;

use App\Services\SnsSettingService;
use App\Support\Feature;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityEventPluginFeatureUpgrade;
use App\Upgrade\Steps\PluginFeatureUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * Runs the compiled `plugin` → `sns_settings` copy against the real OpenPNE 3 `plugin` DDL: a row is
 * written only for a plugin OpenPNE 3 had switched off, so absent / enabled both stay absent here too.
 *
 * MySQL only, like the other upgrade SQL tests.
 */
class PluginFeatureUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        DB::statement('DROP TABLE IF EXISTS `plugin`');
        DB::statement(SourceSchema::default()->createStatement('plugin', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `plugin`');
        }

        parent::tearDown();
    }

    public function test_an_absent_plugin_row_writes_nothing(): void
    {
        // OpenPNE 3 wrote `plugin` rows lazily: no row is an enabled plugin, and enabled units
        // have no row in OpenPNE 4 either.
        $before = DB::table('sns_settings')->count();

        $this->runUpgrade();

        $this->assertSame($before, DB::table('sns_settings')->count());
    }

    public function test_an_enabled_plugin_writes_nothing(): void
    {
        $this->seedPlugin('opDiaryPlugin', isEnabled: 1);
        $before = DB::table('sns_settings')->count();

        $this->runUpgrade();

        $this->assertSame($before, DB::table('sns_settings')->count());
    }

    public function test_a_disabled_plugin_writes_a_zero_row(): void
    {
        $this->seedPlugin('opDiaryPlugin', isEnabled: 0);
        $this->seedPlugin('opMessagePlugin', isEnabled: 1);

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'feature_diary_enabled', 'value' => '0']);
        $this->assertDatabaseMissing('sns_settings', ['key' => 'feature_message_enabled']);
    }

    public function test_the_topic_plugin_takes_events_down_too(): void
    {
        // OpenPNE 3 shipped the topic board and events in one plugin; OpenPNE 4 toggles them
        // separately, so the one disabled row becomes two rows through two steps.
        $this->seedPlugin('opCommunityTopicPlugin', isEnabled: 0);

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'feature_community_topic_enabled', 'value' => '0']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'feature_community_event_enabled', 'value' => '0']);
        // The container itself was not switchable in OpenPNE 3, so it keeps no row (= enabled).
        $this->assertDatabaseMissing('sns_settings', ['key' => 'feature_community_enabled']);
    }

    public function test_unknown_plugin_names_are_ignored(): void
    {
        $this->seedPlugin('opSomeThirdPartyPlugin', isEnabled: 0);
        $before = DB::table('sns_settings')->count();

        $this->runUpgrade();

        $this->assertSame($before, DB::table('sns_settings')->count());
    }

    public function test_the_written_rows_switch_the_units_off_at_runtime(): void
    {
        $this->seedPlugin('opCommunityTopicPlugin', isEnabled: 0);

        $this->runUpgrade();
        app(SnsSettingService::class)->clearCache();

        $this->assertFalse(Feature::CommunityTopic->enabled());
        $this->assertFalse(Feature::CommunityEvent->enabled());
        $this->assertTrue(Feature::Community->enabled());
        $this->assertTrue(Feature::Diary->enabled());
    }

    private function runUpgrade(): void
    {
        $compiler = new InsertSelectCompiler;

        DB::statement($compiler->compile(new PluginFeatureUpgrade));
        DB::statement($compiler->compile(new CommunityEventPluginFeatureUpgrade));
    }

    private function seedPlugin(string $name, int $isEnabled): void
    {
        DB::table('plugin')->insert([
            'name' => $name,
            'is_enabled' => $isEnabled,
            'created_at' => '2018-01-02 03:04:05',
            'updated_at' => '2018-01-02 03:04:05',
        ]);
    }
}
