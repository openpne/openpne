<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Member;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DatabaseMigrations rather than RefreshDatabase: the migration's DDL commits on MySQL, and the next
 * test needs the stock schema back.
 */
class AddLastLoginAtToMembersTableTest extends TestCase
{
    use DatabaseMigrations;

    /** SQLite 3.35 and later drop the column in place and keep the triggers, so only an older SQLite fails this without the fix. */
    public function test_an_ai_account_still_refuses_a_credential_after_down_and_up(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount()->create();
        $migration = $this->migration();

        $migration->down();
        $this->assertCredentialRefused($owner, $aiAccount);

        $migration->up();
        $this->assertCredentialRefused($owner, $aiAccount);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_04_000001_add_last_login_at_to_members_table.php');
    }

    private function assertCredentialRefused(Member $owner, Member $aiAccount): void
    {
        $this->assertRefused(fn () => DB::table('members')->insert([
            'name' => 'Impostor',
            'email' => 'impostor@example.test',
            'owner_member_id' => $owner->getKey(),
        ]));
        $this->assertRefused(fn () => DB::table('members')->where('id', $aiAccount->getKey())->update([
            'password' => 'a-bcrypt-shaped-string',
        ]));
    }

    private function assertRefused(callable $write): void
    {
        try {
            $write();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('the database accepted the write');
    }
}
