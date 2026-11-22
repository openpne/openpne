<?php

namespace Tests\Concerns;

use App\Features\Group\GroupRole;
use App\Models\Group;
use Illuminate\Support\Facades\DB;

trait SqliteBindLimit
{
    /**
     * Counts each statement's bindings, so a list bound past SQLite's 999 before 3.32 fails on every engine.
     *
     * @template T
     *
     * @param  callable(): T  $act
     * @return T
     */
    protected function withinSqliteBindLimit(callable $act): mixed
    {
        $most = 0;
        DB::listen(function ($query) use (&$most): void {
            $most = max($most, count($query->bindings));
        });

        $result = $act();

        $this->assertLessThanOrEqual(999, $most, 'a statement bound more than 999 parameters');

        return $result;
    }

    /**
     * Inserts 100 rows a statement, under the 999 bound parameters SQLite before 3.32 takes.
     *
     * @return list<int>
     */
    protected function manyMembers(int $count, ?Group $joining = null): array
    {
        $after = (int) DB::table('members')->max('id');
        $now = now();

        foreach (array_chunk(range(1, $count), 100) as $part) {
            DB::table('members')->insert(array_map(fn (int $i): array => [
                'name' => "Member {$i}",
                'email' => "bulk-{$after}-{$i}@example.com",
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $part));
        }

        $ids = DB::table('members')->where('id', '>', $after)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($joining !== null) {
            foreach (array_chunk($ids, 100) as $part) {
                DB::table('group_members')->insert(array_map(fn (int $id): array => [
                    'group_id' => $joining->getKey(),
                    'member_id' => $id,
                    'role' => GroupRole::Member->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $part));
            }
        }

        return $ids;
    }
}
