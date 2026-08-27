<?php

declare(strict_types=1);

namespace App\Features\Home;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The ledger read as memory: has this source already been featured in this section?
 *
 * Scoped to the section, always. The bands answer different questions about the same row, so a group
 * that was featured for being new may still be featured for what was said in it, and an event that
 * led a story may still be listed on the calendar.
 *
 * Rows are never swept when their source is deleted (see the create migration), which is what makes
 * this a memory rather than an index of what currently exists.
 */
final class HomeIssueLedger
{
    /**
     * Drop from $query every row this section has already featured.
     *
     * @param  string  $idColumn  qualified id column on the query's table (e.g. `diaries.id`)
     */
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

    /** Whether this section has featured this source in any issue. */
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
     * The column must name its table. Unqualified, it binds to the subquery's own table rather than
     * to the caller's — `id` compares source_id against the ledger row's own id — and the result is
     * a wrong answer, not an error. Whether that answer happens to match the right one depends on
     * whether the two ids coincide, so it can look correct on a database whose ids start over per
     * test and wrong on one whose do not.
     */
    private static function assertQualified(string $idColumn): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $idColumn) !== 1) {
            throw new InvalidArgumentException("[{$idColumn}] must name its table, as `table.column`.");
        }
    }

    /**
     * A recurring section keeps no such memory, so asking it is a mistake at the call site rather
     * than a question with an answer. Returning "not featured" would be the more forgiving reply and
     * the worse one: it looks like a fact, and a caller that consults the ledger for a section which
     * may repeat has confused two rules that only happen to agree today.
     */
    private static function assertRemembers(HomeIssueSection $section): void
    {
        if ($section->recurs()) {
            throw new LogicException("Section [{$section->value}] may feature a source again; it keeps no never-again memory.");
        }
    }
}
