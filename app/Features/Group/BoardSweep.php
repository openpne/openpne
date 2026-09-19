<?php

namespace App\Features\Group;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The reclaiming statements a teardown shares, shaped for a group of any size: reactions are found
 * by subquery and deleted by primary key in chunks, so the sweep locks only the rows it deletes
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
        DB::table('group_topics')->where('group_id', $groupId)->orderBy('id')->lockForUpdate()->count();
        DB::table('group_events')->where('group_id', $groupId)->orderBy('id')->lockForUpdate()->count();
    }

    /**
     * Call inside the teardown's transaction after its parent locks and before any consistent read:
     * the snapshot is then taken under the locks, so these plain reads see every committed row.
     */
    public static function reactions(string $alias, Builder $contentIds): void
    {
        $after = 0;
        do {
            $ids = DB::table('reactions')
                ->where('reactable_type', $alias)
                ->whereIn('reactable_id', $contentIds)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();
            if ($ids !== []) {
                Reaction::query()->whereIn('id', $ids)->delete();
                $after = (int) end($ids);
            }
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
