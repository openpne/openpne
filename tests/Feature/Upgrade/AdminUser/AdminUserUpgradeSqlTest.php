<?php

namespace Tests\Feature\Upgrade\AdminUser;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\AdminUserUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled admin_user → admin_users copy against the real OpenPNE 3 `admin_user` DDL. The row
 * carries over with the OpenPNE 3 MD5 password verbatim (INSERT...SELECT bypasses the model's `hashed`
 * cast), to be rehashed to bcrypt on first login. MySQL only, like the other upgrade SQL tests.
 */
class AdminUserUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

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
