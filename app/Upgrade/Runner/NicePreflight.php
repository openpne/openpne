<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\ActivityThread;
use App\Upgrade\Steps\NiceReactionUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Counts the likes the activity routing leaves behind, so their drop is not silent. It runs before
 * the absent optional tables are materialised, so a source without opLikePlugin is not queried.
 */
final class NicePreflight
{
    private const SAMPLE = 5;

    /**
     * @param  list<string>  $readTables  source tables present and read by the run; `nice` absent means nothing to count
     * @return list<string> warnings
     */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase, array $readTables): array
    {
        if (array_diff(['nice', 'activity_data', 'community'], $readTables) !== []) {
            return [];
        }

        $notMigrated = "`nice`.`foreign_table` = 'A' AND NOT (".NiceReactionUpgrade::onActivity(ActivityThread::migrated('activity_data')).')';
        $sql = 'SELECT `id` FROM '.SourceRef::table('nice').' AS `nice` WHERE '.$notMigrated.' ORDER BY `id`';
        $rows = DB::select((new InsertSelectCompiler)->resolveSourceRefs($sql, $sourcePrefix, $sourceDatabase));

        if ($rows === []) {
            return [];
        }

        return [self::unmigratedActivityLikeMessage(count($rows), array_slice(array_map(static fn (object $r): int => (int) $r->id, $rows), 0, self::SAMPLE))];
    }

    /** @param  list<int>  $ids */
    public static function unmigratedActivityLikeMessage(int $rows, array $ids): string
    {
        return "source `nice` has {$rows} like(s) on activities that are not migrated (e.g. ids ".implode(', ', $ids).') — a like lands where its activity does, so these are not migrated either.';
    }
}
