<?php

namespace App\Features\Group;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** See docs/internals/group-boards.md, "Tearing a group down". */
final class BoardSweep
{
    private const CHUNK = 1000;

    /**
     * Every topic and event row of the group, exclusively: a board writer takes one of these and
     * never the group row. Call under the group row's lock, before any consistent read.
     */
    public static function holdBoards(int $groupId): void
    {
        DB::table('group_topics')->where('group_id', $groupId)->lockForUpdate()->count();
        DB::table('group_events')->where('group_id', $groupId)->lockForUpdate()->count();
    }

    /**
     * Call inside the teardown's transaction, with the parent rows locked before its first consistent
     * read: the snapshot is then taken under the locks, so these plain reads see every committed row.
     *
     * @param  iterable<int>  $parentIds
     */
    public static function comments(string $alias, string $table, string $parentColumn, iterable $parentIds): void
    {
        $pool = [];
        foreach ($parentIds as $parentId) {
            foreach (self::pagesOf($table, $parentColumn, (int) $parentId) as $page) {
                $pool = [...$pool, ...$page];
                while (count($pool) >= self::CHUNK) {
                    self::deleteMatching(DB::table('reactions')->where('reactable_type', $alias)->whereIn('reactable_id', array_splice($pool, 0, self::CHUNK)));
                }
            }
        }
        if ($pool !== []) {
            self::deleteMatching(DB::table('reactions')->where('reactable_type', $alias)->whereIn('reactable_id', $pool));
        }
    }

    /**
     * The reactions on the parent rows themselves, a page of the group's rows at a time by id: a
     * subquery re-run per page of reactions costs chunks times the rows left (measured superlinear at 100k).
     * Call before the rows are deleted, or a page finds nothing to reach.
     */
    public static function rows(string $alias, string $table, int $groupId): void
    {
        $after = 0;
        do {
            $ids = DB::table($table)->where('group_id', $groupId)->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            self::deleteMatching(DB::table('reactions')->where('reactable_type', $alias)->whereIn('reactable_id', $ids));
            $after = (int) end($ids);
        } while (count($ids) === self::CHUNK);
    }

    /**
     * Paged in the index's own order, (number, id) under the parent; `number` repeats, so the id breaks the
     * tie, and is NOT NULL, so no null arm is needed. Spelled as the OR of the two arms: MySQL 8.4 plans
     * a row constructor here as a filter over the whole parent, not a range from the cursor.
     *
     * @return iterable<list<int>>
     */
    private static function pagesOf(string $table, string $parentColumn, int $parentId): iterable
    {
        $cursor = null;
        do {
            $query = DB::table($table)->where($parentColumn, $parentId);
            if ($cursor !== null) {
                [$number, $id] = $cursor;
                $query->where(fn (Builder $after) => $after->where('number', '>', $number)->orWhere(fn (Builder $tie) => $tie->where('number', $number)->where('id', '>', $id)));
            }
            $page = $query->orderBy('number')->orderBy('id')->limit(self::CHUNK)->get(['id', 'number']);
            if ($page->isEmpty()) {
                break;
            }
            yield $page->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $cursor = [(int) $page->last()->number, (int) $page->last()->id];
        } while ($page->count() === self::CHUNK);
    }

    /**
     * Paged in the room index's own order, (created_at, id) under the group. The first page carries no
     * cursor and a null timestamp has its own arm, since a keyset would otherwise never reach a row
     * that sorts before its start — a null or zero date the transfer copied as it was.
     */
    public static function messages(string $alias, int $groupId): void
    {
        $first = true;
        [$at, $id] = [null, 0];
        do {
            // The group id as a literal: with the leading index column bound, MySQL 8.4 plans a large room's continuation page as a filter from its head.
            $query = DB::table('group_messages')->whereRaw('`group_id` = '.$groupId);
            if (! $first) {
                $query->where(function (Builder $after) use ($at, $id): void {
                    if ($at === null) {
                        $after->where(fn (Builder $nulls) => $nulls->whereNull('created_at')->where('id', '>', $id))->orWhereNotNull('created_at');
                    } else {
                        $after->where('created_at', '>', $at)->orWhere(fn (Builder $tie) => $tie->where('created_at', $at)->where('id', '>', $id));
                    }
                });
            }
            $page = $query->orderBy('created_at')->orderBy('id')->limit(self::CHUNK)->get(['id', 'created_at']);
            if ($page->isEmpty()) {
                break;
            }
            self::deleteMatching(DB::table('reactions')->where('reactable_type', $alias)->whereIn('reactable_id', $page->pluck('id')->all()));
            [$at, $id, $first] = [$page->last()->created_at === null ? null : (string) $page->last()->created_at, (int) $page->last()->id, false];
        } while ($page->count() === self::CHUNK);
    }

    private static function deleteMatching(Builder $matching): void
    {
        $after = 0;
        do {
            $ids = (clone $matching)->where('reactions.id', '>', $after)->orderBy('reactions.id')->limit(self::CHUNK)->pluck('reactions.id')->all();
            if ($ids === []) {
                break;
            }
            Reaction::query()->whereIn('id', $ids)->delete();
            $after = (int) end($ids);
        } while (count($ids) === self::CHUNK);
    }

    /**
     * Call after the transaction committed: the bytes are irreversible on a disk backend.
     *
     * @param  list<int>  $fileIds
     */
    public static function files(array $fileIds): void
    {
        foreach (array_chunk($fileIds, self::CHUNK) as $chunk) {
            foreach (File::query()->whereIn('id', $chunk)->get() as $file) {
                $file->delete();
            }
        }
    }
}
