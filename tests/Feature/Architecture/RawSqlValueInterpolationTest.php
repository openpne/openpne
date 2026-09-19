<?php

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A raw SQL fragment may name identifiers and expressions of the code's own; a runtime value goes
 * through a binding. Every fragment built at runtime is listed here with its reason, so a new one
 * has to be argued for.
 */
class RawSqlValueInterpolationTest extends TestCase
{
    /** @var array<string, string> file => why a fragment may be built at runtime there */
    private const ALLOWED = [
        'app/Features/Group/BoardSweep.php' => 'the group id, an int: MySQL plans a large room\'s page as a filter from its head when the leading index column is bound',
        'app/Features/Diary/Queries/MemberDiaryMonthlyCounts.php' => 'the month expression is chosen per driver by the code',
        'app/Features/Timeline/Queries/MentionCandidates.php' => 'the length function is chosen per driver by the code',
        'app/Features/GroupTalk/Queries/GroupTalkMentionCandidates.php' => 'the length function is chosen per driver by the code',
        'app/Features/Member/Queries/SearchMembers.php' => 'visibility and age expressions composed by the code; every value is bound',
        'app/Features/Member/Queries/VisibleSelfIntroductions.php' => 'the visibility expression composed by the code; every value is bound',
    ];

    private const RAW_CALL = '/(?:whereRaw|selectRaw|orderByRaw|groupByRaw|havingRaw|fromRaw|DB::raw)\s*\(\s*(.*)$/';

    public function test_no_raw_sql_fragment_interpolates_a_runtime_value(): void
    {
        $offending = [];
        foreach ($this->sources() as $path => $lines) {
            foreach ($lines as $number => $line) {
                if (preg_match(self::RAW_CALL, $line, $m) === 1 && self::fragmentIsBuilt($m[1]) && ! isset(self::ALLOWED[$path])) {
                    $offending[] = "{$path}:".($number + 1);
                }
            }
        }

        $this->assertSame([], $offending, 'raw SQL with a variable in it: bind the value, or list the file with its reason');
    }

    public function test_every_allowed_file_still_has_the_inlining(): void
    {
        foreach (self::ALLOWED as $path => $reason) {
            $built = false;
            foreach (file(base_path($path), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $built = $built || (preg_match(self::RAW_CALL, $line, $m) === 1 && self::fragmentIsBuilt($m[1]));
            }
            $this->assertTrue($built, "{$path} no longer builds a fragment at runtime; drop it from the list");
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
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || str_starts_with($file->getPathname(), base_path('app/Upgrade'))) {
                continue; // the transfer compiles its SQL from source-schema constants, not runtime values
            }
            yield substr($file->getPathname(), strlen(base_path()) + 1) => file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
        }
    }
}
