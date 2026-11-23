<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Member;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * DatabaseMigrations rather than RefreshDatabase: the migration's DDL commits on MySQL, and the next
 * test needs the stock schema back.
 */
class AddLastLoginAtToMembersTableTest extends TestCase
{
    use DatabaseMigrations;

    /** SQLite 3.35 and later drop the column in place and keep the triggers, so only an older SQLite fails this when down() does not put them back. */
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

    /** Only below SQLite 3.35, where the drop rebuilds members, could a cascade reach these rows. */
    public function test_down_keeps_the_rows_that_cascade_from_members(): void
    {
        [$one, $other] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert(['member_id' => $one->getKey(), 'friend_id' => $other->getKey(), 'created_at' => now()]);

        $migration = $this->migration();

        $migration->down();
        $this->assertSame(1, DB::table('friendships')->count());

        $migration->up();
    }

    /** The fault lands right after the first trigger is dropped, where a down() outside one transaction leaves a trigger missing on every SQLite. */
    public function test_a_failure_while_putting_the_triggers_back_leaves_members_as_it_was(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Only the SQLite path puts triggers back.');
        }

        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount()->create();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'DROP TRIGGER')) {
                $armed = false;

                throw new RuntimeException('injected');
            }
        });

        $thrown = null;
        try {
            $this->migration()->down();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertSame('injected', $thrown?->getMessage());
        $this->assertTrue(Schema::hasColumn('members', 'last_login_at'));
        $this->assertCredentialRefused($owner, $aiAccount);
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
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
