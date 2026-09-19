<?php

namespace App\Features\Group;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The reclaiming statements a teardown shares, shaped for a group of any size: reactions are found
 * a page at a time, in each table's index order, and deleted by primary key in chunks, so the sweep locks only the rows it deletes
 * (docs/internals/group-boards.md, "Tearing a group down").
 */
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
     * One parent at a time, so a page is a range of that parent's comments rather than a sort of the group's.
     */
    public static function comments(string $alias, string $table, string $parentColumn, Builder $parentIds): void
    {
        foreach ((clone $parentIds)->orderBy('id')->pluck('id') as $parentId) {
            self::commentsOf($alias, $table, $parentColumn, (int) $parentId);
        }
    }

    public static function commentsOf(string $alias, string $table, string $parentColumn, int $parentId): void
    {
        $after = 0;
        do {
            $page = DB::table($table)->where($parentColumn, $parentId)->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            if ($page === []) {
                break;
            }
            self::deleteMatching(DB::table('reactions')->where('reactable_type', $alias)->whereIn('reactable_id', $page));
            $after = (int) end($page);
        } while (count($page) === self::CHUNK);
    }

    /** The messages are paged in their index's own order, (created_at, id) under the group, so no page sorts or skips the room. */
    public static function messages(string $alias, int $groupId): void
    {
        [$at, $id] = ['1970-01-01 00:00:00', 0];
        do {
            $page = DB::table('group_messages')
                ->where('group_id', $groupId)
                ->where(fn (Builder $q) => $q->where('created_at', '>', $at)->orWhere(fn (Builder $tie) => $tie->where('created_at', $at)->where('id', '>', $id)))
                ->orderBy('created_at')->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'created_at']);
            if ($page->isEmpty()) {
                break;
            }
            self::deleteMatching(DB::table('reactions')->where('reactable_type', $alias)->whereIn('reactable_id', $page->pluck('id')->all()));
            [$at, $id] = [(string) $page->last()->created_at, (int) $page->last()->id];
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
