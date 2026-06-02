<?php

namespace Tests\Feature\Upgrade\Profile;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MemberProfileUpgrade;
use App\Upgrade\Steps\ProfileOptionUpgrade;
use App\Upgrade\Steps\ProfileUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled profile INSERT...SELECTs against the real OpenPNE 3 DDL, exercising the
 * nested-set flattening: single-value rows, checkbox children (root dropped, flag
 * inherited), custom-date composition, and the public_flag 0 → NULL normalisation.
 *
 * MySQL only — the correlated/self subqueries (CONCAT_WS/LPAD/OFFSET) and source DDL are
 * MySQL features. Profiles/options are upgraded first so the member_profiles FKs resolve.
 */
class MemberProfileUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL.');
        }

        foreach (['member_profile', 'profile_option', 'profile'] as $t) {
            DB::statement("DROP TABLE IF EXISTS `{$t}`");
        }
        DB::statement(SourceSchema::default()->createStatement('profile', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('profile_option', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('member_profile', withoutForeignKeys: true));

        $this->memberId = Member::factory()->create()->getKey();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (['member_profile', 'profile_option', 'profile'] as $t) {
                DB::statement("DROP TABLE IF EXISTS `{$t}`");
            }
        }

        parent::tearDown();
    }

    public function test_single_value_text_is_copied_with_its_flag(): void
    {
        $this->seedProfile(1, 'custom_text', 'input');
        $this->seedMemberProfile(100, 1, ['value' => 'hello', 'public_flag' => 1, 'tree_key' => 100, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', [
            'id' => 100, 'profile_id' => 1, 'value' => 'hello', 'profile_option_id' => null, 'public_flag' => 1,
        ]);
    }

    public function test_preset_select_keeps_value_key(): void
    {
        $this->seedProfile(2, 'op_preset_sex', 'select');
        $this->seedMemberProfile(200, 2, ['value' => 'M', 'public_flag' => 2, 'tree_key' => 200, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 200, 'value' => 'M', 'public_flag' => 2]);
    }

    public function test_custom_select_keeps_option_id(): void
    {
        $this->seedProfile(3, 'custom_sel', 'select');
        $this->seedProfileOption(50, 3);
        $this->seedMemberProfile(300, 3, ['value' => '', 'profile_option_id' => 50, 'public_flag' => 1, 'tree_key' => 300, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 300, 'profile_option_id' => 50]);
    }

    public function test_checkbox_keeps_children_drops_root_and_inherits_root_flag(): void
    {
        $this->seedProfile(4, 'custom_cb', 'checkbox');
        $this->seedProfileOption(60, 4);
        $this->seedProfileOption(61, 4);
        // Root: no option, empty value, flag set (OpenPNE 3 stores the flag on the root only).
        $this->seedMemberProfile(400, 4, ['value' => '', 'public_flag' => 2, 'tree_key' => 400, 'lft' => 1]);
        $this->seedMemberProfile(401, 4, ['value' => '', 'profile_option_id' => 60, 'public_flag' => null, 'tree_key' => 400, 'lft' => 2]);
        $this->seedMemberProfile(402, 4, ['value' => '', 'profile_option_id' => 61, 'public_flag' => null, 'tree_key' => 400, 'lft' => 3]);

        $this->runUpgrade();

        $this->assertDatabaseMissing('member_profiles', ['id' => 400]); // structural root dropped
        $this->assertDatabaseHas('member_profiles', ['id' => 401, 'profile_option_id' => 60, 'public_flag' => 2]);
        $this->assertDatabaseHas('member_profiles', ['id' => 402, 'profile_option_id' => 61, 'public_flag' => 2]);
    }

    public function test_custom_date_is_composed_from_children(): void
    {
        $this->seedProfile(5, 'custom_date', 'date');
        $this->seedMemberProfile(500, 5, ['value' => '', 'public_flag' => 1, 'tree_key' => 500, 'lft' => 1]);
        $this->seedMemberProfile(501, 5, ['value' => '2020', 'tree_key' => 500, 'lft' => 2]);
        $this->seedMemberProfile(502, 5, ['value' => '3', 'tree_key' => 500, 'lft' => 3]);
        $this->seedMemberProfile(503, 5, ['value' => '5', 'tree_key' => 500, 'lft' => 4]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 500, 'value' => '2020-03-05']);
        $this->assertDatabaseMissing('member_profiles', ['id' => 501]); // children dropped
        $this->assertSame(1, MemberProfile::where('profile_id', 5)->count());
    }

    public function test_preset_date_is_single_value(): void
    {
        $this->seedProfile(6, 'op_preset_birthday', 'date');
        $this->seedMemberProfile(600, 6, ['value' => '1990-01-02', 'value_datetime' => '1990-01-02 00:00:00', 'public_flag' => 3, 'tree_key' => 600, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 600, 'value' => '1990-01-02', 'public_flag' => 3]);
    }

    public function test_invalid_public_flag_zero_becomes_null(): void
    {
        $this->seedProfile(7, 'custom_text2', 'input');
        $this->seedMemberProfile(700, 7, ['value' => 'x', 'public_flag' => 0, 'tree_key' => 700, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 700, 'public_flag' => null]);
    }

    public function test_profile_default_public_flag_zero_is_normalized(): void
    {
        // OpenPNE 3's preset form seeds default_public_flag = 0, which is not a valid 1-4 flag.
        DB::table('profile')->insert([
            'id' => 8, 'name' => 'op_preset_sex', 'form_type' => 'select', 'value_type' => 'string',
            'default_public_flag' => 0, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00',
        ]);

        DB::statement((new InsertSelectCompiler)->compile(new ProfileUpgrade));

        $this->assertSame(1, (int) DB::table('profiles')->where('id', 8)->value('default_public_flag'));
    }

    private function runUpgrade(): void
    {
        $compiler = new InsertSelectCompiler;
        DB::statement($compiler->compile(new ProfileUpgrade));
        DB::statement($compiler->compile(new ProfileOptionUpgrade));
        DB::statement($compiler->compile(new MemberProfileUpgrade));
    }

    private function seedProfile(int $id, string $name, string $formType): void
    {
        DB::table('profile')->insert([
            'id' => $id,
            'name' => $name,
            'form_type' => $formType,
            'value_type' => 'string',
            'default_public_flag' => 1,
            'created_at' => '2018-01-01 00:00:00',
            'updated_at' => '2018-01-01 00:00:00',
        ]);
    }

    private function seedProfileOption(int $id, int $profileId): void
    {
        DB::table('profile_option')->insert([
            'id' => $id,
            'profile_id' => $profileId,
            'sort_order' => 0,
            'created_at' => '2018-01-01 00:00:00',
            'updated_at' => '2018-01-01 00:00:00',
        ]);
    }

    private function seedMemberProfile(int $id, int $profileId, array $overrides): void
    {
        DB::table('member_profile')->insert(array_merge([
            'id' => $id,
            'member_id' => $this->memberId,
            'profile_id' => $profileId,
            'profile_option_id' => null,
            'value' => '',
            'value_datetime' => null,
            'public_flag' => null,
            'tree_key' => null,
            'lft' => null,
            'rgt' => null,
            'level' => null,
            'created_at' => '2019-01-01 00:00:00',
            'updated_at' => '2019-01-01 00:00:00',
        ], $overrides));
    }
}
