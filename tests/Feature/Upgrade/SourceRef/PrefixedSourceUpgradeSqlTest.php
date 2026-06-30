<?php

namespace Tests\Feature\Upgrade\SourceRef;

use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Visibility;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MemberPreferenceUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves --source-prefix reaches a step's correlated subqueries end-to-end, not just the FROM table:
 * a prefixed OpenPNE 3 `op_member_config` (a shared-rental install whose source carries a table
 * prefix) sits alongside the unprefixed OpenPNE 4 target, and MemberPreferenceUpgrade — whose filter
 * reads member_config again in a MAX() subquery — must read the prefixed table in both places.
 *
 * DatabaseMigrations (not RefreshDatabase): creating the source table is DDL, which auto-commits on
 * MySQL and cannot be rolled back inside a transaction.
 */
class PrefixedSourceUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        $ddl = SourceSchema::default()->createStatement('member_config', withoutForeignKeys: true);
        DB::statement(str_replace('`member_config`', '`op_member_config`', $ddl));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `op_member_config`');
        }

        parent::tearDown();
    }

    public function test_a_prefixed_source_table_and_its_subquery_are_read(): void
    {
        $member = Member::factory()->create();

        // Two rows for one (member, name): the latest-row-per-name MAX() subquery — which also reads
        // op_member_config — must collapse them to the most recent value.
        DB::table('op_member_config')->insert([
            ['member_id' => $member->getKey(), 'name' => 'diary_public_flag', 'value' => '1', 'name_value_hash' => 'h1', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
            ['member_id' => $member->getKey(), 'name' => 'diary_public_flag', 'value' => '4', 'name_value_hash' => 'h2', 'created_at' => '2020-01-02 00:00:00', 'updated_at' => '2020-01-02 00:00:00'],
        ]);

        DB::statement((new InsertSelectCompiler)->compile(new MemberPreferenceUpgrade, sourcePrefix: 'op_'));

        $this->assertDatabaseCount('member_preferences', 1);
        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->getKey(),
            'key' => PreferenceKey::DiaryDefaultVisibility->value,
            'value' => (string) Visibility::Open->value,
        ]);
    }
}
