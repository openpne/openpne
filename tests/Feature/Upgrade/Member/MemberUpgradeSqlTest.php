<?php

namespace Tests\Feature\Upgrade\Member;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MemberUpgrade;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled member INSERT...SELECT against the real OpenPNE 3 `member` +
 * `member_config` DDL, exercising the credential subqueries (email fallback, verbatim
 * MD5, login-impossible NULLs).
 *
 * MySQL only, like the other upgrade SQL tests: the correlated subqueries and source DDL
 * (TEXT, utf8mb3, DATETIME) are MySQL features. DatabaseMigrations because creating the
 * source tables is DDL, which implicitly commits.
 */
class MemberUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        $this->createSourceTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `sns_config`');
            DB::statement('DROP TABLE IF EXISTS `member_config`');
            DB::statement('DROP TABLE IF EXISTS `member`');
        }

        parent::tearDown();
    }

    public function test_maps_pc_address_to_email_and_carries_the_md5_password_verbatim(): void
    {
        $md5 = md5('secret');
        $this->seedMember(1, 'Alice');
        $this->seedConfig(1, 'pc_address', 'alice@pc.example');
        $this->seedConfig(1, 'password', $md5);

        $this->runUpgrade();

        // The MD5 must land unchanged — INSERT...SELECT bypasses the model's `hashed` cast.
        $this->assertDatabaseHas('members', [
            'id' => 1,
            'name' => 'Alice',
            'email' => 'alice@pc.example',
            'password' => $md5,
        ]);
    }

    public function test_falls_back_to_the_mobile_address_when_there_is_no_pc_address(): void
    {
        $this->seedMember(2, 'Bob');
        $this->seedConfig(2, 'mobile_address', 'bob@mobile.example');
        $this->seedConfig(2, 'password', md5('x'));

        $this->runUpgrade();

        $this->assertDatabaseHas('members', ['id' => 2, 'email' => 'bob@mobile.example']);
    }

    public function test_pc_address_wins_over_mobile_address(): void
    {
        $this->seedMember(3, 'Carol');
        $this->seedConfig(3, 'pc_address', 'carol@pc.example');
        $this->seedConfig(3, 'mobile_address', 'carol@mobile.example');
        $this->seedConfig(3, 'password', md5('x'));

        $this->runUpgrade();

        $this->assertDatabaseHas('members', ['id' => 3, 'email' => 'carol@pc.example']);
    }

    public function test_member_without_address_or_password_migrates_as_login_impossible(): void
    {
        // An inactive pre-registration: only the unconfirmed `pc_address_pre` exists, plus an
        // empty `pc_address` that NULLIF must collapse to NULL rather than an empty login id.
        $this->seedMember(4, 'Dave');
        $this->seedConfig(4, 'pc_address_pre', 'dave@pre.example');
        $this->seedConfig(4, 'pc_address', '');

        $this->runUpgrade();

        $this->assertDatabaseHas('members', [
            'id' => 4,
            'email' => null,
            'password' => null,
        ]);
    }

    public function test_duplicate_login_email_aborts_the_copy(): void
    {
        // Two members claiming the same address must fail loudly on the unique index rather
        // than silently merge — the operator resolves the collision in the source.
        $this->seedMember(5, 'Eve');
        $this->seedConfig(5, 'pc_address', 'dup@example.com');
        $this->seedConfig(5, 'password', md5('x'));
        $this->seedMember(6, 'Frank');
        $this->seedConfig(6, 'pc_address', 'dup@example.com');
        $this->seedConfig(6, 'password', md5('y'));

        $this->expectException(QueryException::class);
        $this->runUpgrade();
    }

    public function test_maps_profile_page_public_flag_to_profile_visibility(): void
    {
        // member_config[profile_page_public_flag] (OpenPNE 3 public_flag) → Visibility.
        $this->seedMember(10, 'WebPublic');
        $this->seedConfig(10, 'profile_page_public_flag', '4'); // web → Open(0)
        $this->seedMember(11, 'FriendsOnly');
        $this->seedConfig(11, 'profile_page_public_flag', '2'); // friend → Friends(2)
        $this->seedMember(12, 'NoFlag');                        // unset → Members(1)

        $this->runUpgrade();

        $this->assertSame(0, (int) DB::table('members')->where('id', 10)->value('profile_visibility'));
        $this->assertSame(2, (int) DB::table('members')->where('id', 11)->value('profile_visibility'));
        $this->assertSame(1, (int) DB::table('members')->where('id', 12)->value('profile_visibility'));
    }

    public function test_sns_global_profile_visibility_overrides_a_stale_member_flag(): void
    {
        // OpenPNE 3 prefers the SNS-wide setting; a stale member_config=4 must NOT leak the
        // page to guests when the SNS-wide value is non-web.
        $this->seedSnsConfig('is_allow_config_public_flag_profile_page', '1'); // truthy, non-web
        $this->seedMember(13, 'Stale');
        $this->seedConfig(13, 'profile_page_public_flag', '4');

        $this->runUpgrade();

        $this->assertSame(1, (int) DB::table('members')->where('id', 13)->value('profile_visibility')); // Members, not Open
    }

    public function test_sns_global_web_public_applies_to_a_member_without_a_flag(): void
    {
        $this->seedSnsConfig('is_allow_config_public_flag_profile_page', '4'); // SNS-wide web-public
        $this->seedMember(14, 'NoFlag');

        $this->runUpgrade();

        $this->assertSame(0, (int) DB::table('members')->where('id', 14)->value('profile_visibility')); // Open
    }

    public function test_maps_language_to_a_supported_locale_slug(): void
    {
        $this->seedMember(20, 'Ja');
        $this->seedConfig(20, 'language', 'ja_JP');
        $this->seedMember(21, 'En');
        $this->seedConfig(21, 'language', 'en_US');
        $this->seedMember(22, 'Unknown');
        $this->seedConfig(22, 'language', 'fr_FR'); // unsupported → NULL (request-time chain decides)
        $this->seedMember(23, 'None');              // no language config → NULL

        $this->runUpgrade();

        $this->assertSame('ja', DB::table('members')->where('id', 20)->value('locale'));
        $this->assertSame('en', DB::table('members')->where('id', 21)->value('locale'));
        $this->assertNull(DB::table('members')->where('id', 22)->value('locale'));
        $this->assertNull(DB::table('members')->where('id', 23)->value('locale'));
    }

    public function test_latest_member_config_value_wins_for_a_duplicate(): void
    {
        // member_config has no (member_id, name) unique; the most recent row must win rather
        // than resolving by storage order.
        $this->seedMember(30, 'Dup');
        $this->seedConfigWithId(100, 30, 'pc_address', 'old@example.com');
        $this->seedConfigWithId(200, 30, 'pc_address', 'new@example.com');

        $this->runUpgrade();

        $this->assertDatabaseHas('members', ['id' => 30, 'email' => 'new@example.com']);
    }

    public function test_carries_the_is_login_rejected_ban_flag(): void
    {
        // The admin ban must survive the upgrade so a rejected member stays unable to log in.
        $this->seedMember(40, 'Banned');
        DB::table('member')->where('id', 40)->update(['is_login_rejected' => 1]);
        $this->seedMember(41, 'Allowed'); // seedMember defaults is_login_rejected = 0

        $this->runUpgrade();

        $this->assertSame(1, (int) DB::table('members')->where('id', 40)->value('is_login_rejected'));
        $this->assertSame(0, (int) DB::table('members')->where('id', 41)->value('is_login_rejected'));
    }

    public function test_no_member_row_is_dropped(): void
    {
        $this->seedMember(7, 'Grace');
        $this->seedConfig(7, 'pc_address', 'grace@pc.example');
        $this->seedConfig(7, 'password', md5('x'));
        $this->seedMember(8, 'Heidi'); // no credentials at all

        $this->runUpgrade();

        $this->assertSame(2, Member::query()->count());
    }

    private function createSourceTables(): void
    {
        // The real OpenPNE 3 DDL, minus FKs so the two tables stand alone in this test.
        DB::statement('DROP TABLE IF EXISTS `sns_config`');
        DB::statement('DROP TABLE IF EXISTS `member_config`');
        DB::statement('DROP TABLE IF EXISTS `member`');
        DB::statement(SourceSchema::default()->createStatement('member', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('member_config', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('sns_config', withoutForeignKeys: true));
    }

    private function seedSnsConfig(string $name, string $value): void
    {
        DB::table('sns_config')->insert(['name' => $name, 'value' => $value]);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new MemberUpgrade));
    }

    private function seedMember(int $id, string $name): void
    {
        DB::table('member')->insert([
            'id' => $id,
            'name' => $name,
            'is_login_rejected' => 0,
            'is_active' => 1,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    private function seedConfig(int $memberId, string $name, string $value): void
    {
        DB::table('member_config')->insert([
            'member_id' => $memberId,
            'name' => $name,
            'value' => $value,
            'name_value_hash' => md5($name.$value),
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    private function seedConfigWithId(int $id, int $memberId, string $name, string $value): void
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
