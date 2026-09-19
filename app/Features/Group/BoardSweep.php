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

    /** Call inside the teardown's transaction, after its parent locks: the content ids are read plain and are complete under them. */
    public static function reactions(string $alias, Builder $contentIds): void
    {
        $ids = DB::table('reactions')
            ->where('reactable_type', $alias)
            ->whereIn('reactable_id', $contentIds)
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
