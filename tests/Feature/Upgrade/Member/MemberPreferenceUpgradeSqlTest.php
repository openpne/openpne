<?php

namespace Tests\Feature\Upgrade\Member;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MemberPreferenceUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled member_config → member_preferences INSERT...SELECT against the real
 * OpenPNE 3 `member_config` DDL: the name→key and public_flag→Visibility CASEs, the
 * latest-row dedup for the KV table's missing (member_id, name) unique, and the restriction
 * to the registered PreferenceKey names.
 *
 * MySQL only, like the other upgrade SQL tests. A target member is created first so the
 * member_preferences.member_id FK resolves.
 */
class MemberPreferenceUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL.');
        }

        DB::statement('DROP TABLE IF EXISTS `member_config`');
        DB::statement(SourceSchema::default()->createStatement('member_config', withoutForeignKeys: true));

        $this->memberId = Member::factory()->create()->getKey();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `member_config`');
        }

        parent::tearDown();
    }

    public function test_maps_diary_public_flag_to_the_diary_default_visibility_key(): void
    {
        $this->seedConfig(1, $this->memberId, 'diary_public_flag', '2'); // friend → Friends(2)

        $this->runUpgrade();

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $this->memberId, 'key' => 'diary_default_visibility', 'value' => '2',
        ]);
    }

    public function test_maps_web_public_flag_to_open(): void
    {
        $this->seedConfig(1, $this->memberId, 'diary_public_flag', '4'); // web → Open(0)

        $this->runUpgrade();

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $this->memberId, 'key' => 'diary_default_visibility', 'value' => '0',
        ]);
    }

    public function test_maps_age_public_flag_to_the_age_visibility_key(): void
    {
        $this->seedConfig(1, $this->memberId, 'age_public_flag', '3'); // private → Private(3)

        $this->runUpgrade();

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $this->memberId, 'key' => 'age_visibility', 'value' => '3',
        ]);
    }

    public function test_latest_row_wins_for_a_duplicated_name(): void
    {
        // member_config has no (member_id, name) unique; the most recent row must win and the
        // (member_id, key) unique target must see exactly one row.
        $this->seedConfig(10, $this->memberId, 'diary_public_flag', '2'); // older → Friends
        $this->seedConfig(20, $this->memberId, 'diary_public_flag', '3'); // newer → Private

        $this->runUpgrade();

        $this->assertSame(1, DB::table('member_preferences')->where('key', 'diary_default_visibility')->count());
        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $this->memberId, 'key' => 'diary_default_visibility', 'value' => '3',
        ]);
    }

    public function test_unregistered_config_names_are_not_migrated(): void
    {
        $this->seedConfig(1, $this->memberId, 'time_zone', 'Asia/Tokyo');
        $this->seedConfig(2, $this->memberId, 'language', 'ja_JP');

        $this->runUpgrade();

        $this->assertSame(0, DB::table('member_preferences')->count());
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new MemberPreferenceUpgrade));
    }

    private function seedConfig(int $id, int $memberId, string $name, string $value): void
    {
        DB::table('member_config')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'name' => $name,
            'value' => $value,
            'name_value_hash' => md5($name.$value),
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }
}
