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
        DB::statement('DROP TABLE IF EXISTS `member_config`');
        DB::statement('DROP TABLE IF EXISTS `member`');
        DB::statement(SourceSchema::default()->createStatement('member', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('member_config', withoutForeignKeys: true));
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
}
