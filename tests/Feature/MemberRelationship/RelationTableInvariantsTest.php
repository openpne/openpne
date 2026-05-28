<?php

namespace Tests\Feature\MemberRelationship;

use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DB-level invariants for the three relation tables (friendships,
 * friend_requests, member_blocks). Only enforces what the schema can
 * enforce: self-pair CHECK, composite primary key uniqueness, and
 * cascade delete. Multi-table exclusion (e.g. friendships ↔ friend_requests
 * for the same pair) is the Action layer's responsibility — see PR B.
 */
class RelationTableInvariantsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, string}> */
    public static function relationTables(): array
    {
        return [
            'friendships' => ['friendships', 'member_id', 'friend_id'],
            'friend_requests' => ['friend_requests', 'requester_id', 'target_id'],
            'member_blocks' => ['member_blocks', 'blocker_id', 'blocked_id'],
        ];
    }

    #[DataProvider('relationTables')]
    public function test_self_pair_is_rejected_at_db_layer(string $table, string $a, string $b): void
    {
        $m = Member::factory()->create();

        $this->expectException(QueryException::class);

        DB::table($table)->insert([$a => $m->getKey(), $b => $m->getKey()]);
    }

    #[DataProvider('relationTables')]
    public function test_composite_primary_key_blocks_duplicates(string $table, string $a, string $b): void
    {
        [$x, $y] = Member::factory()->count(2)->create()->all();

        DB::table($table)->insert([$a => $x->getKey(), $b => $y->getKey()]);

        $this->expectException(QueryException::class);

        DB::table($table)->insert([$a => $x->getKey(), $b => $y->getKey()]);
    }

    #[DataProvider('relationTables')]
    public function test_cascade_delete_when_primary_fk_member_is_removed(string $table, string $a, string $b): void
    {
        [$x, $y] = Member::factory()->count(2)->create()->all();
        DB::table($table)->insert([$a => $x->getKey(), $b => $y->getKey()]);

        $this->assertDatabaseCount($table, 1);

        $x->delete();

        $this->assertDatabaseCount($table, 0);
    }

    /**
     * Cascade must also fire when the secondary FK column's member is
     * deleted. MySQL/InnoDB auto-creates the index for this path; SQLite
     * does not, so the migrations declare it. This test guards against a
     * future migration accidentally dropping `cascadeOnDelete()` on the
     * secondary FK and leaving orphan rows behind.
     */
    #[DataProvider('relationTables')]
    public function test_cascade_delete_when_secondary_fk_member_is_removed(string $table, string $a, string $b): void
    {
        [$x, $y] = Member::factory()->count(2)->create()->all();
        DB::table($table)->insert([$a => $x->getKey(), $b => $y->getKey()]);

        $this->assertDatabaseCount($table, 1);

        $y->delete();

        $this->assertDatabaseCount($table, 0);
    }

    /**
     * Reverse-direction lookup (the secondary FK column) must be indexed.
     * MySQL/InnoDB auto-creates this index; SQLite does not, so the
     * migrations declare it explicitly. This test makes the regression
     * surface immediately if someone removes the explicit `->index(...)`.
     */
    #[DataProvider('relationTables')]
    public function test_secondary_fk_column_has_an_index(string $table, string $a, string $b): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = collect(DB::select("PRAGMA index_list('{$table}')"));
            $matched = $indexes->contains(function ($row) use ($b) {
                $info = collect(DB::select("PRAGMA index_info('{$row->name}')"));

                return $info->count() === 1 && $info->first()->name === $b;
            });
        } else {
            $indexes = collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Column_name = ?", [$b]));
            $matched = $indexes->isNotEmpty();
        }

        $this->assertTrue($matched, "Index on {$table}.{$b} is missing");
    }

    /**
     * The schema deliberately allows the same (member_a, member_b) pair to
     * coexist in friendships and friend_requests. Preventing that is the
     * Action layer's responsibility (PR B SendFriendRequest must reject when
     * the pair is already friends, AcceptFriendRequest must delete the
     * pending request atomically with the friendship insert). This test
     * documents that the DB does not block the inconsistent state on its
     * own — so the Action guard is load-bearing, not redundant.
     */
    public function test_friendships_and_friend_requests_can_coexist_at_db_layer(): void
    {
        [$a, $b] = Member::factory()->count(2)->create()->all();

        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
        DB::table('friend_requests')->insert([
            'requester_id' => $a->getKey(),
            'target_id' => $b->getKey(),
        ]);

        $this->assertDatabaseCount('friendships', 2);
        $this->assertDatabaseCount('friend_requests', 1);
    }
}
