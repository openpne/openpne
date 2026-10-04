<?php

namespace Tests\Feature\Upgrade\Feature;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\FriendFeatureUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class FriendFeatureUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['sns_config'];
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
