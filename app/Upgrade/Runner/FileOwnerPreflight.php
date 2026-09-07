<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\FileUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * A file must have one owner: FilePolicy answers for a file by its single owner, so a file two owning
 * rows point at would let one owner's audience read the other's. OpenPNE 3 never made the columns
 * unique, so the count runs before the first write (docs/internals/upgrade.md, "Source preflight").
 */
final class FileOwnerPreflight
{
    private const SAMPLE = 5;

    /**
     * @param  list<string>  $readTables  source tables the run's steps read; a reference whose SELECT needs any other table is not queried
     * @return string|null the error, or null when every referenced file has at most one owner
     */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase, array $readTables): ?string
    {
        $selects = [];
        foreach ((new FileUpgrade)->ownedFileReferences() as $reference) {
            $select = FileUpgrade::ownerRowsSelect($reference);
            if (array_diff([$reference['table'], ...SourceRef::tablesIn($select)], $readTables) === []) {
                $selects[] = $select;
            }
        }
        if ($selects === []) {
            return null;
        }

        $sql = 'SELECT `file_id`, COUNT(DISTINCT CONCAT(`owner_type`, \':\', `owner_id`)) AS `owners` FROM ('
            .implode(' UNION ALL ', $selects)
            .') AS `owner_rows` GROUP BY `file_id` HAVING `owners` > 1 ORDER BY `file_id`';

        $shared = DB::select((new InsertSelectCompiler)->resolveSourceRefs($sql, $sourcePrefix, $sourceDatabase));
        if ($shared === []) {
            return null;
        }

        return self::sharedFileOwnerMessage(
            count($shared),
            array_slice(array_map(static fn (object $r): int => (int) $r->file_id, $shared), 0, self::SAMPLE),
        );
    }

    /** @param  list<int>  $fileIds */
    public static function sharedFileOwnerMessage(int $files, array $fileIds): string
    {
        return "source `file` has {$files} file(s) referenced by more than one owning row (e.g. file ids ".implode(', ', $fileIds)
            .') — OpenPNE 4 gives a file one owner, whose audience would then read it from the other place too. Duplicate the file for one of the rows, or drop one reference, then re-run.';
    }
}
