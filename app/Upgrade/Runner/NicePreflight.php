<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\ActivityThread;
use App\Upgrade\Steps\NiceReactionUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Counts the likes the transfer leaves behind, so their drop is not silent, and the ones the step
 * would fail on. It runs before the absent optional tables are materialised, so a source without
 * opLikePlugin is not queried.
 */
final class NicePreflight
{
    private const SAMPLE = 5;

    /** opLikePlugin's one-letter targets other than an activity, in its own order. */
    private const OTHER_TABLES = ['D' => 'diaries', 'd' => 'diary comments', 't' => 'topic comments', 'e' => 'event comments'];

    private string $prefix = '';

    private ?string $database = null;

    /**
     * @param  list<string>  $readTables  source tables present and read by the run; `nice` absent means nothing to count
     */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase, array $readTables): ActivityPreflightReport
    {
        // The activity and community tables are core, and the refused member scope already required
        // them of the structural check; only the plugin's own table can be missing.
        if (! in_array('nice', $readTables, true)) {
            return new ActivityPreflightReport([], []);
        }
        $this->prefix = $sourcePrefix;
        $this->database = $sourceDatabase;

        $errors = [];
        $warnings = [];

        // Two likes by one member on one activity: opLikePlugin's unique index arrived after 0.9, and
        // the OpenPNE 4 unique key would fail the INSERT on the second row mid-run.
        $migrated = NiceReactionUpgrade::onActivity(ActivityThread::migrated('activity_data'));
        $twins = DB::select($this->resolve(
            'SELECT MAX(`nice`.`id`) AS `id` FROM '.SourceRef::table('nice').' AS `nice` WHERE '.$migrated
            .' GROUP BY `nice`.`member_id`, `nice`.`foreign_id` HAVING COUNT(*) > 1 ORDER BY `id`',
        ));
        if ($twins !== []) {
            $errors[] = self::duplicateLikeMessage(count($twins), array_slice(array_map(static fn (object $r): int => (int) $r->id, $twins), 0, self::SAMPLE));
        }

        [$rows, $ids] = $this->rows(NiceReactionUpgrade::onTable('A').' AND NOT ('.$migrated.')');
        if ($rows > 0) {
            $warnings[] = self::unmigratedActivityLikeMessage($rows, $ids);
        }

        // One pass over every other letter, the four the plugin writes and whatever else its API let
        // through — a NULL included, which the 0.9 schema allowed.
        foreach ($this->grouped('NOT '.NiceReactionUpgrade::onTable('A').' OR `nice`.`foreign_table` IS NULL') as $letter => [$rows, $ids]) {
            $warnings[] = isset(self::OTHER_TABLES[$letter])
                ? self::otherLikeMessage(self::OTHER_TABLES[$letter], $rows, $ids)
                : self::unknownTableLikeMessage($letter, $rows, $ids);
        }

        return new ActivityPreflightReport($errors, $warnings);
    }

    /** @param  list<int>  $ids  the later row of each pair */
    public static function duplicateLikeMessage(int $pairs, array $ids): string
    {
        return "source `nice` has {$pairs} member/activity pair(s) liked more than once (e.g. the later rows, ids ".implode(', ', $ids).') — OpenPNE 4 keeps one reaction per member and emoji, so the reaction step would fail mid-run. Keep only the earliest row of each pair in the source, then re-run.';
    }

    /** @param  list<int>  $ids */
    public static function unknownTableLikeMessage(string $letter, int $rows, array $ids): string
    {
        return "source `nice` has {$rows} like(s) on foreign_table '{$letter}', which opLikePlugin itself does not write (e.g. ids ".implode(', ', $ids).') — a third-party plugin or a source customisation. Not migrated.';
    }

    /** @return array<string, array{int, list<int>}> letter ('' for NULL) => [rows, first ids] */
    private function grouped(string $where): array
    {
        $from = 'FROM '.SourceRef::table('nice').' AS `nice` WHERE ('.$where.')';
        $result = [];
        foreach (DB::select($this->resolve("SELECT `nice`.`foreign_table` AS `letter`, COUNT(*) AS `rows` {$from} GROUP BY `nice`.`foreign_table` ORDER BY `nice`.`foreign_table`")) as $group) {
            $match = $group->letter === null ? '`nice`.`foreign_table` IS NULL' : '`nice`.`foreign_table` = CAST(? AS BINARY)';
            $ids = array_map(
                static fn (object $r): int => (int) $r->id,
                DB::select($this->resolve("SELECT `nice`.`id` AS `id` {$from} AND {$match} ORDER BY `nice`.`id` LIMIT ".self::SAMPLE), $group->letter === null ? [] : [$group->letter]),
            );
            $result[(string) $group->letter] = [(int) $group->rows, $ids];
        }

        return $result;
    }

    /** @param  list<int>  $ids */
    public static function unmigratedActivityLikeMessage(int $rows, array $ids): string
    {
        return "source `nice` has {$rows} like(s) on activities that are not migrated (e.g. ids ".implode(', ', $ids).') — a like lands where its activity does, so these are not migrated either.';
    }

    /** @param  list<int>  $ids */
    public static function otherLikeMessage(string $what, int $rows, array $ids): string
    {
        return "source `nice` has {$rows} like(s) on {$what} (e.g. ids ".implode(', ', $ids).') — not migrated: only likes on activities are carried yet.';
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
