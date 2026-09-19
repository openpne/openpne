<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\NiceReactionUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Counts the likes the transfer leaves behind, so their drop is not silent, and the ones a step
 * would fail on. It runs before the absent optional tables are materialised, so a source without
 * opLikePlugin is not queried, and a letter whose own plugin is absent is counted without its table.
 */
final class NicePreflight
{
    private const SAMPLE = 5;

    private const RECORD_NAMES = ['D' => 'diaries', 'd' => 'diary comments', 't' => 'topic comments', 'e' => 'event comments'];

    private string $prefix = '';

    private ?string $database = null;

    /**
     * @param  list<string>  $readTables  source tables present and read by the run; `nice` absent means nothing to count
     */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase, array $readTables): PreflightReport
    {
        if (! in_array('nice', $readTables, true)) {
            return new PreflightReport([], []);
        }
        $this->prefix = $sourcePrefix;
        $this->database = $sourceDatabase;

        $errors = [];
        $warnings = [];
        $carried = [];
        $known = [];

        foreach (NiceReactionUpgrade::carriedBranches() as $letter => $branch) {
            $known[] = NiceReactionUpgrade::onTable($letter);
            // Only a record letter can lack its table: the activity and community tables are core, required by the structural check.
            $table = NiceReactionUpgrade::RECORD_TABLES[$letter] ?? null;
            if ($table !== null && ! in_array($table, $readTables, true)) {
                [$rows, $ids] = $this->rows(NiceReactionUpgrade::onTable($letter));
                if ($rows > 0) {
                    $warnings[] = self::uninstalledTargetLikeMessage(self::RECORD_NAMES[$letter], $rows, $ids);
                }

                continue;
            }
            $carried[] = $branch;

            [$rows, $ids] = $this->rows(NiceReactionUpgrade::onTable($letter).' AND NOT ('.$branch.')');
            if ($rows > 0) {
                $warnings[] = $letter === 'A'
                    ? self::unmigratedActivityLikeMessage($rows, $ids)
                    : self::goneTargetLikeMessage(self::RECORD_NAMES[$letter], $rows, $ids);
            }
        }

        // A table built before opLikePlugin declared its unique index was never given one by a
        // migration, so a doubled like can exist and would fail the OpenPNE 4 unique key mid-run.
        $twins = DB::select($this->resolve(
            'SELECT MAX(`nice`.`id`) AS `id` FROM '.SourceRef::table('nice').' AS `nice` WHERE ('.implode(') OR (', $carried).')'
            .' GROUP BY `nice`.`member_id`, CAST(`nice`.`foreign_table` AS BINARY), `nice`.`foreign_id` HAVING COUNT(*) > 1 ORDER BY `id`',
        ));
        if ($twins !== []) {
            $errors[] = self::duplicateLikeMessage(count($twins), array_slice(array_map(static fn (object $r): int => (int) $r->id, $twins), 0, self::SAMPLE));
        }

        foreach ($this->grouped('NOT ('.implode(') AND NOT (', $known).')') as [$letter, $rows, $ids]) {
            $warnings[] = self::unknownTableLikeMessage($letter, $rows, $ids);
        }

        return new PreflightReport($errors, $warnings);
    }

    /** @param  list<int>  $ids  the later row of each pair */
    public static function duplicateLikeMessage(int $pairs, array $ids): string
    {
        return "source `nice` has {$pairs} member/target pair(s) liked more than once (e.g. the later rows, ids ".implode(', ', $ids).') — OpenPNE 4 keeps one reaction per member and emoji, so the reaction step would fail mid-run. Keep only the earliest row of each pair in the source, then re-run.';
    }

    /** @param  list<int>  $ids */
    public static function unmigratedActivityLikeMessage(int $rows, array $ids): string
    {
        return "source `nice` has {$rows} like(s) on activities that are not migrated (e.g. ids ".implode(', ', $ids).') — a like lands where its activity does, so these are not migrated either.';
    }

    /** @param  list<int>  $ids */
    public static function goneTargetLikeMessage(string $what, int $rows, array $ids): string
    {
        return "source `nice` has {$rows} like(s) on {$what} that no longer exist (e.g. ids ".implode(', ', $ids).') — nothing to land on, so not migrated.';
    }

    /** @param  list<int>  $ids */
    public static function uninstalledTargetLikeMessage(string $what, int $rows, array $ids): string
    {
        return "source `nice` has {$rows} like(s) on {$what}, whose plugin is not installed on this source (e.g. ids ".implode(', ', $ids).') — nothing to land on, so not migrated.';
    }

    /** @param  list<int>  $ids */
    public static function unknownTableLikeMessage(string $letter, int $rows, array $ids): string
    {
        return "source `nice` has {$rows} like(s) on foreign_table '{$letter}', which opLikePlugin itself does not write (e.g. ids ".implode(', ', $ids).') — a third-party plugin or a source customisation. Not migrated.';
    }

    /**
     * Grouped on the bytes, not the column: the source's collation may fold `D` and `d`, which are
     * two tables.
     *
     * @return list<array{string, int, list<int>}> [letter, rows, first ids]
     */
    private function grouped(string $where): array
    {
        $from = 'FROM '.SourceRef::table('nice').' AS `nice` WHERE ('.$where.')';
        $letter = 'CAST(`nice`.`foreign_table` AS BINARY)';
        $result = [];
        foreach (DB::select($this->resolve("SELECT {$letter} AS `letter`, COUNT(*) AS `rows` {$from} GROUP BY {$letter} ORDER BY {$letter}")) as $group) {
            $ids = array_map(
                static fn (object $r): int => (int) $r->id,
                DB::select($this->resolve("SELECT `nice`.`id` AS `id` {$from} AND {$letter} = CAST(? AS BINARY) ORDER BY `nice`.`id` LIMIT ".self::SAMPLE), [$group->letter]),
            );
            $result[] = [(string) $group->letter, (int) $group->rows, $ids];
        }

        return $result;
    }

    /** @return array{int, list<int>} */
    private function rows(string $where): array
    {
        $from = 'FROM '.SourceRef::table('nice').' AS `nice` WHERE '.$where;
        $count = (int) DB::scalar($this->resolve("SELECT COUNT(*) {$from}"));
        if ($count === 0) {
            return [0, []];
        }

        $ids = array_map(
            static fn (object $r): int => (int) $r->id,
            DB::select($this->resolve("SELECT `nice`.`id` AS `id` {$from} ORDER BY `nice`.`id` LIMIT ".self::SAMPLE)),
        );

        return [$count, $ids];
    }

    private function resolve(string $sql): string
    {
        return (new InsertSelectCompiler)->resolveSourceRefs($sql, $this->prefix, $this->database);
    }
}
