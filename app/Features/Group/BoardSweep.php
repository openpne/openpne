<?php

namespace App\Features\Group;

use App\Models\File;
use App\Models\Reaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The reclaiming statements a teardown shares, shaped for a group of any size: reactions are found
 * a page at a time and deleted by primary key in chunks, so the sweep locks only the rows it deletes
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
     * Paged by content id and then by reaction id, so PHP holds a page of each and no statement grows.
     */
    public static function reactions(string $alias, Builder $contentIds): void
    {
        $after = 0;
        do {
            $page = (clone $contentIds)->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            if ($page === []) {
                break;
            }
            self::deleteMatching(DB::table('reactions')->where('reactable_type', $alias)->whereIn('reactable_id', $page));
            $after = (int) end($page);
        } while (count($page) === self::CHUNK);
    }

    /** For content that outnumbers its reactions, as talk does: paged by reaction id over the one subquery, which is cheap on its own. */
    public static function reactionsOn(string $alias, Builder $contentIds): void
    {
        self::deleteMatching(DB::table('reactions')->where('reactable_type', $alias)->whereIn('reactable_id', $contentIds));
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
