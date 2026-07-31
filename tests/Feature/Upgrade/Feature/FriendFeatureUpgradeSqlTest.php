<?php

namespace Tests\Feature\Upgrade\Feature;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FriendFeatureUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * Runs the compiled `sns_config.enable_friend_link` → `sns_settings` copy against the real OpenPNE 3
 * DDL. Only the disabled row carries over, so both of OpenPNE 3's ways of saying "friends are on"
 * (an absent row, an explicit '1') stay absent in OpenPNE 4.
 *
 * MySQL only, like the other upgrade SQL tests.
 */
class FriendFeatureUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        DB::statement('DROP TABLE IF EXISTS `sns_config`');
        DB::statement(SourceSchema::default()->createStatement('sns_config', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `sns_config`');
        }

        parent::tearDown();
    }

    public function test_a_disabled_friend_link_writes_a_zero_row(): void
    {
        DB::table('sns_config')->insert(['name' => 'enable_friend_link', 'value' => '0']);

        $this->runUpgrade();

        $this->assertDatabaseHas('sns_settings', ['key' => 'feature_friend_enabled', 'value' => '0']);
    }

    public function test_an_enabled_friend_link_writes_nothing(): void
    {
        DB::table('sns_config')->insert(['name' => 'enable_friend_link', 'value' => '1']);
        $before = DB::table('sns_settings')->count();

        $this->runUpgrade();

        $this->assertSame($before, DB::table('sns_settings')->count());
        $this->assertDatabaseMissing('sns_settings', ['key' => 'feature_friend_enabled']);
    }

    public function test_an_absent_friend_link_row_writes_nothing(): void
    {
        DB::table('sns_config')->insert(['name' => 'sns_name', 'value' => 'My SNS']);

        $this->runUpgrade();

        $this->assertDatabaseMissing('sns_settings', ['key' => 'feature_friend_enabled']);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new FriendFeatureUpgrade));
    }
}
