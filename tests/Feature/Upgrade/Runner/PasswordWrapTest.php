<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Auth\PasswordScheme;
use App\Models\AdminUser;
use App\Models\Member;
use App\Models\UpgradeState;
use App\Upgrade\Runner\PasswordWrap;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The post-walk wrap pass: every bare-MD5 password becomes bcrypt(md5hex) +
 * password_scheme, everything else is left alone, and the bare-MD5 predicate makes
 * re-runs and resumes no-ops over already-wrapped rows. MySQL only (REGEXP predicate),
 * like the rest of the upgrade suite.
 */
class PasswordWrapTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The wrap pass selects rows with a MySQL REGEXP.');
        }
    }

    /** @return list<string> */
    private function runWrap(array $tables = ['members', 'admin_users']): array
    {
        $lines = [];
        $ok = (new PasswordWrap)->run($tables, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertTrue($ok);

        return $lines;
    }

    private function seedBareMd5Member(string $plaintext): Member
    {
        $member = Member::factory()->create();
        DB::table('members')->where('id', $member->getKey())->update(['password' => md5($plaintext)]);

        return $member->fresh();
    }

    public function test_wraps_bare_md5_rows_and_leaves_everything_else_alone(): void
    {
        $legacy = $this->seedBareMd5Member('secret');
        $bcryptMember = Member::factory()->create(); // factory password is already bcrypt
        $bcryptBefore = $bcryptMember->fresh()->password;
        $nullMember = Member::factory()->create();
        DB::table('members')->where('id', $nullMember->getKey())->update(['password' => null]);
        $admin = AdminUser::factory()->create();
        DB::table('admin_users')->where('id', $admin->getKey())->update(['password' => md5('admin-pass')]);

        $this->runWrap();

        $legacy = $legacy->fresh();
        // Wrapped: bcrypt over the md5 hex, at the import-time cost, flagged.
        $this->assertStringStartsWith('$2y$10$', $legacy->password);
        $this->assertTrue(Hash::check(md5('secret'), $legacy->password));
        $this->assertSame(PasswordScheme::Md5Bcrypt->value, $legacy->password_scheme);

        $this->assertSame($bcryptBefore, $bcryptMember->fresh()->password);
        $this->assertNull($bcryptMember->fresh()->password_scheme);
        $this->assertNull($nullMember->fresh()->password);
        $this->assertNull($nullMember->fresh()->password_scheme);

        $admin = $admin->fresh();
        $this->assertTrue(Hash::check(md5('admin-pass'), $admin->password));
        $this->assertSame(PasswordScheme::Md5Bcrypt->value, $admin->password_scheme);

        $this->assertSame(1, UpgradeState::where('step_key', 'password_wrap_members')
            ->where('status', UpgradeState::STATUS_COMPLETED)->where('rows_affected', 1)->count());
        $this->assertSame(1, UpgradeState::where('step_key', 'password_wrap_admin_users')
            ->where('status', UpgradeState::STATUS_COMPLETED)->where('rows_affected', 1)->count());
    }

    public function test_a_completed_pass_is_skipped_and_a_failed_one_resumes(): void
    {
        $wrapped = $this->seedBareMd5Member('first');
        $this->runWrap();
        $wrappedHash = $wrapped->fresh()->password;

        // Completed → SKIP, nothing rewritten (a rewrap would produce a different salt).
        $lines = $this->runWrap();
        $this->assertContains('SKIP password_wrap_members: already completed', $lines);
        $this->assertSame($wrappedHash, $wrapped->fresh()->password);

        // A failed pass resumes: only the still-bare row matches the predicate again.
        UpgradeState::where('step_key', 'password_wrap_members')->update(['status' => UpgradeState::STATUS_FAILED]);
        $remaining = $this->seedBareMd5Member('second');

        $this->runWrap(['members']);

        $this->assertSame($wrappedHash, $wrapped->fresh()->password);
        $this->assertTrue(Hash::check(md5('second'), $remaining->fresh()->password));
        $this->assertSame(1, (int) UpgradeState::where('step_key', 'password_wrap_members')->value('rows_affected'));
    }

    public function test_only_tables_owned_by_the_run_are_wrapped(): void
    {
        $legacy = $this->seedBareMd5Member('secret');

        $this->runWrap(['friendships']);

        $this->assertSame(md5('secret'), $legacy->fresh()->password);
        $this->assertSame(0, UpgradeState::count());
    }

    public function test_plan_writes_nothing(): void
    {
        $legacy = $this->seedBareMd5Member('secret');
        $lines = [];

        (new PasswordWrap)->plan(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertStringContainsString('PLAN', implode("\n", $lines));
        $this->assertSame(md5('secret'), $legacy->fresh()->password);
        $this->assertSame(0, UpgradeState::count());
    }
}
