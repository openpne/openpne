<?php

declare(strict_types=1);

namespace App\Features\Home;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The ledger read as memory: has this source already been featured in this section? Rows are never
 * swept when their source is deleted, which is what makes this a memory rather than an index of what
 * currently exists (docs/internals/home-issues.md, "Never again — per section").
 */
final class HomeIssueLedger
{
    /** @param  string  $idColumn  qualified id column on the query's table (e.g. `diaries.id`) */
    public static function excludeFeatured(Builder $query, HomeIssueSection $section, string $sourceType, string $idColumn): void
    {
        self::assertRemembers($section);
        self::assertQualified($idColumn);

        $query->whereNotExists(function (Builder $sub) use ($section, $sourceType, $idColumn) {
            $sub->select(DB::raw(1))
                ->from('home_issue_items')
                ->where('home_issue_items.section', $section->value)
                ->where('home_issue_items.source_type', $sourceType)
                ->whereColumn('home_issue_items.source_id', $idColumn);
        });
    }

    public static function wasFeatured(HomeIssueSection $section, string $sourceType, int $sourceId): bool
    {
        self::assertRemembers($section);

        return DB::table('home_issue_items')
            ->where('section', $section->value)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /**
     * The column must name its table: unqualified it binds to the subquery's own table, and the
     * result is a wrong answer rather than an error. Whether that answer happens to match the right
     * one depends on whether the two ids coincide, so it can look correct on one database and not on
     * another.
     */
    private static function assertQualified(string $idColumn): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $idColumn) !== 1) {
            throw new InvalidArgumentException("[{$idColumn}] must name its table, as `table.column`.");
        }
    }

    /**
     * A recurring section keeps no such memory, so asking it is a mistake at the call site rather
     * than a question with an answer. Returning "not featured" would look like a fact.
     */
    private static function assertRemembers(HomeIssueSection $section): void
    {
        if ($section->recurs()) {
            throw new LogicException("Section [{$section->value}] may feature a source again; it keeps no never-again memory.");
        }
    }
}
