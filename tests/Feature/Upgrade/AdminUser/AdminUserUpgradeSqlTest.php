<?php

namespace Tests\Feature\Upgrade\AdminUser;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\AdminUserUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class AdminUserUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['admin_user'];
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
