<?php

namespace App\Features\Group;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The reclaiming statements a teardown shares, shaped for a group of any size: reactions are found
 * by subquery, read once, and deleted by primary key in chunks, so the sweep locks only the rows it deletes
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
     * read: the snapshot is then taken under the locks, so this plain read sees every committed row.
     * One read, not a page per chunk: re-running the subquery per chunk made the hold quadratic.
     */
    public static function reactions(string $alias, Builder $contentIds): void
    {
        $ids = DB::table('reactions')
            ->where('reactable_type', $alias)
            ->whereIn('reactable_id', $contentIds)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            Reaction::query()->whereIn('id', $chunk)->delete();
        }
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
