<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\LoginPreflight;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Runner\Warning;
use App\Upgrade\Steps\MemberUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class LoginPreflightTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['member', 'member_config', 'admin_user'];
    }

    public function test_it_counts_the_logins_that_would_carry_whitespace_at_either_end(): void
    {
        $this->seedMember(1, ['pc_address' => 'plain@example.com']);
        $this->seedMember(2, ['pc_address' => 'trailing@example.com ']);
        $this->seedMember(3, ['pc_address' => '', 'mobile_address' => "\u{3000}mobile@example.com"]);
        // The latest pc_address is what migrates, so the earlier padded one is not counted.
        $this->seedMember(4, ['pc_address' => ' old@example.com']);
        $this->seedMember(4, ['pc_address' => 'new@example.com']);
        $this->seedMember(5, ['pc_address' => 'inactive@example.com '], isActive: 0);

        $report = (new LoginPreflight)->inspect([new MemberUpgrade], '', null);

        $this->assertSame([], $report->errors);
        $this->assertEquals([
            new Warning(LoginPreflight::W_MEMBER_EMAIL, LoginPreflight::message(LoginPreflight::W_MEMBER_EMAIL, 2, [2, 3]), 2),
        ], $report->warnings);
    }

    public function test_it_counts_the_member_emails_with_a_character_outside_printable_ascii(): void
    {
        $this->seedMember(1, ['pc_address' => 'たろう@example.com']);
        $this->seedMember(2, ['pc_address' => 'ｕｓｅｒ@example.com']);
        $this->seedMember(3, ['pc_address' => "a\x01b@example.com"]);
        $this->seedMember(4, ['pc_address' => 'a b@example.com']);
        $this->seedMember(5, ['pc_address' => 'taro..yamada@docomo.ne.jp']);
        $this->seedMember(6, ['pc_address' => 'padded@example.com ']);
        $this->seedMember(7, ['pc_address' => ' じろう@example.com']);

        $report = (new LoginPreflight)->inspect([new MemberUpgrade], '', null);

        $this->assertEquals([
            new Warning(LoginPreflight::W_MEMBER_EMAIL, LoginPreflight::message(LoginPreflight::W_MEMBER_EMAIL, 2, [6, 7]), 2),
            new Warning(LoginPreflight::W_MEMBER_EMAIL_NON_ASCII, LoginPreflight::message(LoginPreflight::W_MEMBER_EMAIL_NON_ASCII, 5, [1, 2, 3, 4, 7]), 5),
        ], $report->warnings);
    }

    public function test_a_run_warns_and_migrates_the_value_as_it_is(): void
    {
        $this->seedMember(2, ['pc_address' => 'trailing@example.com ', 'password' => md5('x')]);
        $lines = [];

        $ok = (new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade]))->run(
            new RunOptions,
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $this->assertTrue($ok, implode("\n", $lines));
        $this->assertContains('WARN '.LoginPreflight::W_MEMBER_EMAIL.': '.LoginPreflight::message(LoginPreflight::W_MEMBER_EMAIL, 1, [2]), $lines);
        $this->assertSame('trailing@example.com ', DB::table('members')->where('id', 2)->value('email'));
    }

    public function test_it_is_silent_without_padded_logins(): void
    {
        $this->seedMember(1, ['pc_address' => 'plain@example.com']);
        $this->seedMember(2, []);

        $this->assertSame([], (new LoginPreflight)->inspect([new MemberUpgrade], '', null)->warnings);
    }

    public function test_the_source_administrators_are_counted_and_none_is_copied(): void
    {
        $this->seedMember(1, ['pc_address' => 'plain@example.com', 'password' => md5('x')]);
        $this->seedAdmin(7, 'root');
        $this->seedAdmin(8, 'staff');
        $lines = [];

        $this->assertEquals(
            [new Warning(LoginPreflight::W_ADMIN_USER_NOT_COPIED, LoginPreflight::message(LoginPreflight::W_ADMIN_USER_NOT_COPIED, 2, []), 2)],
            (new LoginPreflight)->inspect([new MemberUpgrade], '', null)->warnings,
        );
        $ok = (new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade]))->run(
            new RunOptions,
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $this->assertTrue($ok, implode("\n", $lines));
        $this->assertContains('WARN '.LoginPreflight::W_ADMIN_USER_NOT_COPIED.': '.LoginPreflight::message(LoginPreflight::W_ADMIN_USER_NOT_COPIED, 2, []), $lines);
        $this->assertSame(0, DB::table('admin_users')->count());
        $this->assertSame(0, DB::table('members')->whereNotNull('admin_granted_at')->count());
    }

    public function test_a_source_without_admin_user_is_silent_about_it(): void
    {
        DB::statement('drop table admin_user');

        $this->assertSame([], (new LoginPreflight)->inspect([new MemberUpgrade], '', null)->warnings);
    }

    /** @param  array<string, string>  $config */
    private function seedMember(int $id, array $config, int $isActive = 1): void
    {
        DB::table('member')->insertOrIgnore([
            'id' => $id, 'name' => "Member {$id}", 'is_login_rejected' => 0, 'is_active' => $isActive,
            'created_at' => '2018-03-04 12:34:56', 'updated_at' => '2019-06-07 01:02:03',
        ]);
        foreach ($config as $name => $value) {
            DB::table('member_config')->insert([
                'member_id' => $id, 'name' => $name, 'value' => $value, 'name_value_hash' => md5($name.$value),
                'created_at' => '2018-03-04 12:34:56', 'updated_at' => '2019-06-07 01:02:03',
            ]);
        }
    }

    private function seedAdmin(int $id, string $username): void
    {
        DB::table('admin_user')->insert([
            'id' => $id, 'username' => $username, 'password' => md5('secret'),
            'created_at' => '2018-01-02 03:04:05', 'updated_at' => '2018-01-02 03:04:05',
        ]);
    }
}
