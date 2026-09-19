<?php

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A raw SQL fragment may name identifiers and expressions of the code's own; a runtime value goes
 * through a binding. Every fragment that `app/` builds at runtime, the upgrade aside, is listed here
 * by its call's whole argument text with its reason, so a new or changed one has to be argued for.
 */
class RawSqlValueInterpolationTest extends TestCase
{
    /** @var array<string, array<string, string>> file => the call's argument text, whole => why it may be built */
    private const ALLOWED = [
        'app/Features/Group/BoardSweep.php' => [
            '\'`group_id` = \'.$groupId);' => 'the group id, an int: MySQL plans a large room\'s page as a filter from its head when the leading index column is bound',
        ],
        'app/Features/Group/BoardBumpedAt.php' => [
            '"UPDATE {$table} SET bumped_at = ".self::definition($table, $comments, $fk).\' WHERE id = ?\', [$thread->getKey()]);' => 'the board\'s table and column names come from a class constant; the id is bound',
            '"UPDATE {$table} SET bumped_at = ".self::definition($table, $comments, $fk));' => 'the board\'s table and column names come from a class constant',
            '"SELECT COUNT(*) FROM {$table} WHERE bumped_at <> ".self::definition($table, $comments, $fk));' => 'the board\'s table and column names come from a class constant',
        ],
        'app/Features/Diary/Queries/MemberDiaryMonthlyCounts.php' => [
            '"{$ym} as ym, count(*) as total")' => 'the month expression is chosen per driver by the code',
        ],
        'app/Features/Timeline/Queries/MentionCandidates.php' => [
            '"{$length}(members.name) <= ?", [self::MAX_NAME])' => 'the length function is chosen per driver by the code',
        ],
        'app/Features/GroupTalk/Queries/GroupTalkMentionCandidates.php' => [
            '"{$length}(members.name) <= ?", [self::MAX_NAME])' => 'the length function is chosen per driver by the code',
        ],
        'app/Features/Member/Queries/SearchMembers.php' => [
            '"{$effVis} <= {$this->clearanceCase()}", [$viewerId, $viewerId]);' => 'the visibility expression is composed by the code from an enum default; the viewer id is bound',
            '"({$expr} >= ? AND {$expr} <= ?)", [$from, $to])' => 'the age expression is chosen per driver by the code; the bounds are bound',
            '"({$expr} >= ? OR {$expr} <= ?)", [$from, $to]);' => 'the age expression is chosen per driver by the code; the bounds are bound',
            '"{$expr} >= ?", [$from]);' => 'the age expression is chosen per driver by the code; the bound is bound',
            '"{$expr} <= ?", [$to]);' => 'the age expression is chosen per driver by the code; the bound is bound',
            '"(({$effAge} <= {$this->clearanceCase()}) AND (({$effAge} <> 0) OR ({$allowWeb})))",' => 'the age visibility expression is composed by the code from enum values and driver-chosen column names; the viewer id is bound',
        ],
        'app/Features/Member/Queries/VisibleSelfIntroductions.php' => [
            '"{$effVis} <= {$this->clearanceCase()}", [$viewerId, $viewerId])' => 'the visibility expression is composed by the code from an enum default; the viewer id is bound',
        ],
    ];

    private const RAW_CALL = '/(?:whereRaw|selectRaw|orderByRaw|groupByRaw|havingRaw|fromRaw|DB::raw|DB::(?:statement|unprepared|select|selectOne|selectResultSets|cursor|scalar|insert|update|delete|affectingStatement)|new (?:Query)?Expression)\s*\(\s*(.*)$/';

    public function test_no_raw_sql_fragment_interpolates_a_runtime_value(): void
    {
        $offending = [];
        foreach ($this->sources() as $path => $lines) {
            foreach (self::builtFragments($lines) as $number => $argument) {
                if (! self::allowed($path, $argument)) {
                    $offending[] = "{$path}:{$number}";
                }
            }
        }

        $this->assertSame([], $offending, 'raw SQL with a variable in it: bind the value, or list the fragment with its reason');
    }

    public function test_every_allowed_fragment_is_still_built(): void
    {
        foreach (self::ALLOWED as $path => $fragments) {
            $built = iterator_to_array(self::builtFragments(file(base_path($path), FILE_IGNORE_NEW_LINES) ?: []), false);
            foreach (array_keys($fragments) as $argument) {
                $this->assertMatchesRegularExpression('/^[\'"]/', $argument, 'an allowed argument is the call\'s whole text, starting with its quoted fragment');
                $this->assertContains($argument, $built, "{$path} no longer builds the fragment {$argument}; drop it from the list");
            }
        }
    }

    private static function allowed(string $path, string $argument): bool
    {
        return isset(self::ALLOWED[$path][$argument]);
    }

    /**
     * A call whose argument list continues on the next line is judged by that line.
     *
     * @param  list<string>  $lines
     * @return iterable<int, string> 1-based line => the fragment argument
     */
    private static function builtFragments(array $lines): iterable
    {
        foreach ($lines as $index => $line) {
            if (preg_match(self::RAW_CALL, $line, $m) !== 1) {
                continue;
            }
            $argument = trim(trim($m[1]) === '' ? ($lines[$index + 1] ?? '') : $m[1]);
            if (self::fragmentIsBuilt($argument)) {
                yield $index + 1 => $argument;
            }
        }
    }

    /** The fragment argument alone: a literal string is fine, a literal with an interpolation, a concatenation, a sprintf or a bare variable is built. */
    private static function fragmentIsBuilt(string $argument): bool
    {
        if (preg_match('/^\d+\s*[,)]/', $argument) === 1) {
            return false;
        }
        if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'\s*([,)]|\.)/', $argument, $m) === 1) {
            return $m[2] === '.';
        }
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*([,)]|\.)/', $argument, $m) === 1) {
            return $m[2] === '.' || str_contains($m[1], '$');
        }

        return true;
    }

    /** @return iterable<string, list<string>> */
    private function sources(): iterable
    {
        $root = base_path('app');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || str_starts_with($file->getPathname(), base_path('app/Upgrade'))) {
                continue; // The upgrade's fragments carry operator-supplied schema identifiers from the command's options, never member input.
            }
            yield substr($file->getPathname(), strlen(base_path()) + 1) => file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
        }
    }
}
