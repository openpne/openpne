<?php

namespace Tests\Feature\Upgrade\Verify;

use App\Auth\PasswordScheme;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\AdminUserUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\Steps\SnsSettingUpgrade;
use App\Upgrade\Verify\UpgradeVerifier;
use App\Upgrade\Verify\VerifyReport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end over the real runner: the walk lands the OpenPNE 3 MD5 verbatim, the
 * post-walk wrap pass converts it, and verify-upgrade's Check C holds the cutover to
 * zero bare-MD5 rows / no malformed or unknown schemes.
 */
class VerifyPasswordsSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The upgrade runner and Check C run on MySQL.');
        }

        $this->createSourceTables();
        $this->seedSources();

        $ok = (new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade, new AdminUserUpgrade, new SnsSettingUpgrade]))
            ->run(new RunOptions);
        $this->assertTrue($ok, 'the upgrade run (walk + wrap) should succeed');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (['sns_config', 'member_config', 'member', 'admin_user'] as $table) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
        }

        parent::tearDown();
    }

    public function test_the_run_wraps_passwords_and_a_clean_result_passes_check_c(): void
    {
        $member = DB::table('members')->where('id', 1)->first();
        $this->assertTrue(Hash::check(md5('member-secret'), $member->password));
        $this->assertSame(PasswordScheme::Md5Bcrypt->value, $member->password_scheme);

        $admin = DB::table('admin_users')->where('id', 1)->first();
        $this->assertTrue(Hash::check(md5('admin-secret'), $admin->password));
        $this->assertSame(PasswordScheme::Md5Bcrypt->value, $admin->password_scheme);

        // A member with no password config imports as NULL and is not wrapped.
        $this->assertNull(DB::table('members')->where('id', 2)->value('password'));
        $this->assertNull(DB::table('members')->where('id', 2)->value('password_scheme'));

        [$report, $out] = $this->verify();
        $this->assertFalse($report->failed(), $out);
        $this->assertStringContainsString('PASS passwords:members:wrapped', $out);
        $this->assertStringContainsString('PASS passwords:admin_users:wrapped', $out);
        // The runner's post-success surface_mode stamp adds a row sns_settings' step did not
        // produce; Check A must not read it as target drift.
        $this->assertStringContainsString('PASS SnsSettingUpgrade', $out);
    }

    public function test_a_remaining_bare_md5_row_fails_check_c(): void
    {
        DB::table('members')->where('id', 1)->update(['password' => md5('member-secret'), 'password_scheme' => null]);

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL passwords:members:wrapped', $out);
    }

    public function test_a_flagged_row_without_a_bcrypt_hash_fails_check_c(): void
    {
        DB::table('admin_users')->where('id', 1)->update(['password' => 'not-a-hash']);

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL passwords:admin_users:scheme', $out);
    }

    public function test_an_unknown_scheme_fails_check_c(): void
    {
        DB::table('members')->where('id', 1)->update(['password_scheme' => 'argon-mystery']);

        [$report, $out] = $this->verify();

        $this->assertTrue($report->failed());
        $this->assertStringContainsString('FAIL passwords:members:known_schemes', $out);
    }

    /** @return array{0: VerifyReport, 1: string} */
    private function verify(): array
    {
        $lines = [];
        $report = (new UpgradeVerifier(new InsertSelectCompiler, [new MemberUpgrade, new AdminUserUpgrade, new SnsSettingUpgrade]))
            ->verify(new RunOptions, function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        return [$report, implode("\n", $lines)];
    }

    private function createSourceTables(): void
    {
        foreach (['sns_config', 'member_config', 'member', 'admin_user'] as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
        foreach (['member', 'member_config', 'sns_config', 'admin_user'] as $table) {
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    private function seedSources(): void
    {
        DB::table('member')->insert([
            ['id' => 1, 'name' => 'Alice', 'is_login_rejected' => 0, 'is_active' => 1, 'created_at' => '2018-03-04 12:34:56', 'updated_at' => '2019-06-07 01:02:03'],
            ['id' => 2, 'name' => 'NoLogin', 'is_login_rejected' => 0, 'is_active' => 1, 'created_at' => '2018-03-04 12:34:56', 'updated_at' => '2019-06-07 01:02:03'],
        ]);
        DB::table('member_config')->insert([
            ['member_id' => 1, 'name' => 'pc_address', 'value' => 'alice@pc.example', 'name_value_hash' => md5('x1'), 'created_at' => '2018-03-04 12:34:56', 'updated_at' => '2019-06-07 01:02:03'],
            ['member_id' => 1, 'name' => 'password', 'value' => md5('member-secret'), 'name_value_hash' => md5('x2'), 'created_at' => '2018-03-04 12:34:56', 'updated_at' => '2019-06-07 01:02:03'],
        ]);
        DB::table('admin_user')->insert([
            'id' => 1, 'username' => 'root-admin', 'password' => md5('admin-secret'),
            'created_at' => '2018-03-04 12:34:56', 'updated_at' => '2019-06-07 01:02:03',
        ]);
    }
}
