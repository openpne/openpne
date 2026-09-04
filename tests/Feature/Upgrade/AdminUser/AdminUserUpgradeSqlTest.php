<?php

namespace Tests\Feature\Upgrade\AdminUser;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\AdminUserUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/** Runs the compiled admin_user copy against the real OpenPNE 3 DDL; MySQL only. */
class AdminUserUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        // Source `admin_user` and target `admin_users` are distinct tables (the rename), so both coexist.
        DB::statement('DROP TABLE IF EXISTS `admin_user`');
        DB::statement(SourceSchema::default()->createStatement('admin_user', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `admin_user`');
        }

        parent::tearDown();
    }

    public function test_migrates_admin_with_the_legacy_md5_password_verbatim(): void
    {
        $md5 = md5('secret');
        DB::table('admin_user')->insert([
            'id' => 7,
            'username' => 'root',
            'password' => $md5,
            'created_at' => '2018-01-02 03:04:05',
            'updated_at' => '2018-01-02 03:04:05',
        ]);

        DB::statement((new InsertSelectCompiler)->compile(new AdminUserUpgrade));

        $this->assertDatabaseHas('admin_users', [
            'id' => 7,
            'username' => 'root',
            'password' => $md5,        // verbatim MD5 (the cast is bypassed), not re-hashed
            'remember_token' => null,  // no OpenPNE 3 source → schema default
            'created_at' => '2018-01-02 03:04:05',
        ]);
    }
}
