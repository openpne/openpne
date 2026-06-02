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
 * inherited), custom-date composition, and the public_flag → Visibility mapping. Source
 * rows carry OpenPNE 3's public_flag; the target stores App\Support\Visibility values.
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
            'id' => 100, 'profile_id' => 1, 'value' => 'hello', 'profile_option_id' => null, 'visibility' => 1, // SNS → Members
        ]);
    }

    public function test_preset_select_keeps_value_key(): void
    {
        $this->seedProfile(2, 'op_preset_sex', 'select');
        // OpenPNE 3 stores the choice value (Man), not M.
        $this->seedMemberProfile(200, 2, ['value' => 'Man', 'public_flag' => 2, 'tree_key' => 200, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 200, 'value' => 'Man', 'visibility' => 2]); // friend → Friends
    }

    public function test_text_form_type_value_is_copied_and_folded_to_input(): void
    {
        // OpenPNE 3 presets (postal_code/telephone_number) use form_type 'text'.
        $this->seedProfile(12, 'op_preset_postal_code', 'text');
        $this->seedMemberProfile(1200, 12, ['value' => '123-4567', 'public_flag' => 1, 'tree_key' => 1200, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 1200, 'value' => '123-4567']);
        $this->assertSame('input', DB::table('profiles')->where('id', 12)->value('form_type'));
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
        $this->assertDatabaseHas('member_profiles', ['id' => 401, 'profile_option_id' => 60, 'visibility' => 2]); // friend → Friends, inherited from root
        $this->assertDatabaseHas('member_profiles', ['id' => 402, 'profile_option_id' => 61, 'visibility' => 2]);
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

    public function test_custom_date_with_incomplete_children_is_null(): void
    {
        // Only year + month present (no day): OpenPNE 3 returns null, so we must not store a
        // malformed "2021-03".
        $this->seedProfile(9, 'custom_date2', 'date');
        $this->seedMemberProfile(900, 9, ['value' => '', 'tree_key' => 900, 'lft' => 1]);
        $this->seedMemberProfile(901, 9, ['value' => '2021', 'tree_key' => 900, 'lft' => 2]);
        $this->seedMemberProfile(902, 9, ['value' => '3', 'tree_key' => 900, 'lft' => 3]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 900, 'value' => null]);
    }

    public function test_undefined_datetime_sentinel_becomes_null(): void
    {
        $original = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        DB::statement("SET SESSION sql_mode = ''"); // allow the zero-date sentinel into the source row

        try {
            $this->seedProfile(10, 'op_preset_birthday', 'date');
            $this->seedMemberProfile(1000, 10, ['value' => '', 'value_datetime' => '0000-00-00 00:00:00', 'public_flag' => 1, 'tree_key' => 1000, 'lft' => 1]);

            $this->runUpgrade();

            $this->assertNull(DB::table('member_profiles')->where('id', 1000)->value('value_datetime'));
        } finally {
            DB::statement("SET SESSION sql_mode = '{$original}'");
        }
    }

    public function test_preset_date_is_single_value(): void
    {
        $this->seedProfile(6, 'op_preset_birthday', 'date');
        $this->seedMemberProfile(600, 6, ['value' => '1990-01-02', 'value_datetime' => '1990-01-02 00:00:00', 'public_flag' => 3, 'tree_key' => 600, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 600, 'value' => '1990-01-02', 'visibility' => 3]); // private → Private
    }

    public function test_web_public_flag_maps_to_open(): void
    {
        $this->seedProfile(11, 'custom_web', 'input');
        $this->seedMemberProfile(1100, 11, ['value' => 'x', 'public_flag' => 4, 'tree_key' => 1100, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 1100, 'visibility' => 0]); // web → Open
    }

    public function test_invalid_public_flag_zero_becomes_null(): void
    {
        $this->seedProfile(7, 'custom_text2', 'input');
        $this->seedMemberProfile(700, 7, ['value' => 'x', 'public_flag' => 0, 'tree_key' => 700, 'lft' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('member_profiles', ['id' => 700, 'visibility' => null]);
    }

    public function test_profile_default_public_flag_maps_to_default_visibility(): void
    {
        // OpenPNE 3's preset form seeds default_public_flag = 0 (invalid) → Members(1); web=4 → Open(0).
        DB::table('profile')->insert([
            ['id' => 8, 'name' => 'op_preset_sex', 'form_type' => 'select', 'value_type' => 'string', 'default_public_flag' => 0, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
            ['id' => 81, 'name' => 'custom_web2', 'form_type' => 'input', 'value_type' => 'string', 'default_public_flag' => 4, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
        ]);

        DB::statement((new InsertSelectCompiler)->compile(new ProfileUpgrade));

        $this->assertSame(1, (int) DB::table('profiles')->where('id', 8)->value('default_visibility'));
        $this->assertSame(0, (int) DB::table('profiles')->where('id', 81)->value('default_visibility'));
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
