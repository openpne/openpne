<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\FileUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Counts the opUploadFilePlugin files (`/m…/` or `/c…/` names) no owner arm claims, so the operator
 * learns before the walk which old body links will answer 404 (docs/internals/upgrade.md, "OpenPNE 3
 * upload files"). A WARN, not an abort: the rows migrate ownerless like any other unclaimed file.
 */
final class UploadFileOwnerPreflight
{
    private const SAMPLE = 5;

    /** @param  list<string>  $readTables  source tables this run reads; an owner arm needing any other table is left out (its rows are not claimable here either) */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase, array $readTables): PreflightReport
    {
        $upgrade = new FileUpgrade;
        $claims = [];
        foreach ($upgrade->ownedFileReferences() as $reference) {
            $claim = FileUpgrade::ownerExists($reference);
            if (array_diff([$reference['table'], ...SourceRef::tablesIn($claim)], $readTables) === []) {
                $claims[] = $claim;
            }
        }
        foreach ($upgrade->namePrefixOwners() as $spec) {
            if (in_array($spec['table'], $readTables, true)) {
                $claims[] = FileUpgrade::nameOwnerExists($spec);
            }
        }
        $prefixes = implode('', array_map(static fn (array $spec): string => $spec['prefix'], $upgrade->namePrefixOwners()));
        $from = 'FROM '.SourceRef::table('file')." AS `file` WHERE REGEXP_LIKE(`file`.`name`, '^/[{$prefixes}][0-9]+/', 'c')"
            .($claims === [] ? '' : ' AND NOT ('.implode(' OR ', $claims).')');
        $resolve = fn (string $sql): string => (new InsertSelectCompiler)->resolveSourceRefs($sql, $sourcePrefix, $sourceDatabase);

        $count = (int) DB::scalar($resolve("SELECT COUNT(*) {$from}"));
        if ($count === 0) {
            return new PreflightReport([], []);
        }

        $ids = array_map(
            static fn (object $r): int => (int) $r->id,
            DB::select($resolve("SELECT `file`.`id` AS `id` {$from} ORDER BY `file`.`id` LIMIT ".self::SAMPLE)),
        );

        return new PreflightReport([], [self::ownerlessUploadMessage($count, $ids)]);
    }

    /** @param  list<int>  $fileIds */
    public static function ownerlessUploadMessage(int $files, array $fileIds): string
    {
        return "source `file` has {$files} upload-plugin file(s) whose member or community the upgrade does not carry (e.g. file ids ".implode(', ', $fileIds)
            .') — they arrive with no owner, so their /f/show links answer 404; delete them in the source, or afterwards in the admin file list.';
    }
}
