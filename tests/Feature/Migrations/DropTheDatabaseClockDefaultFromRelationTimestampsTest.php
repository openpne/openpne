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
class DropTheDatabaseClockDefaultFromRelationTimestampsTest extends TestCase
{
    use DatabaseMigrations;

    private const PAIRS = [
        'friendships' => ['member_id', 'friend_id'],
        'friend_requests' => ['requester_id', 'target_id'],
        'member_blocks' => ['blocker_id', 'blocked_id'],
    ];

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_05_000001_drop_the_database_clock_default_from_relation_timestamps.php');
    }

    public function test_down_restores_the_database_default_and_up_drops_it_again(): void
    {
        [$one, $other] = Member::factory()->count(2)->create()->all();
        $migration = $this->migration();

        $migration->down();
        DB::table('friendships')->insert(['member_id' => $one->getKey(), 'friend_id' => $other->getKey()]);
        $this->assertNotNull(DB::table('friendships')->value('created_at'));

        $migration->up();
        $this->assertRefused(fn () => DB::table('friendships')->insert(['member_id' => $other->getKey(), 'friend_id' => $one->getKey()]));
        $this->assertSame(1, DB::table('friendships')->count());
    }

    /** The SQLite rebuild drops both halves of the distinct-pair trigger; the migration puts both back. */
    public function test_the_distinct_pair_rule_still_holds_on_insert_and_update_after_the_rebuild(): void
    {
        [$one, $other] = Member::factory()->count(2)->create()->all();
        $migration = $this->migration();
        $migration->down();
        $migration->up();

        foreach (self::PAIRS as $table => [$a, $b]) {
            $this->assertRefused(fn () => DB::table($table)->insert([$a => $one->getKey(), $b => $one->getKey(), 'created_at' => now()]));

            DB::table($table)->insert([$a => $one->getKey(), $b => $other->getKey(), 'created_at' => now()]);
            $this->assertRefused(fn () => DB::table($table)->where($a, $one->getKey())->update([$b => $one->getKey()]));
        }
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
