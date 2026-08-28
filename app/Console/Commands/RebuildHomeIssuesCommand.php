<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsHomeIssues;
use App\Features\Home\Actions\PublishHomeIssue;
use App\Features\Home\Data\HomeIssueDay;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Republishes the archive from a day onwards, each day over its own window.
 *
 * For when what qualifies has changed: an issue is a ledger of what the rules admitted on the day it
 * was written, and it does not re-read them. Every issue from the day on is dropped and each day
 * republished oldest first, so the never-again ledger is rebuilt in the order it would have been
 * written and the numbers count on from the issues left standing.
 *
 * One transaction end to end: an archive with half its issues dropped is not an archive. That also
 * makes the dry run exact — it runs the whole rebuild, numbers included, and rolls it back.
 */
class RebuildHomeIssuesCommand extends Command
{
    use ReportsHomeIssues;

    protected $signature = 'openpne:rebuild-home-issues
        {--from= : First day to rebuild (YYYY-MM-DD); by default the first day the archive covers}
        {--dry-run : Run the whole rebuild and roll it back, reporting what the archive would become}';

    protected $description = 'Republish every home issue from a day onwards, each over its own window';

    public function handle(PublishHomeIssue $publish): int
    {
        $now = CarbonImmutable::now();
        $latest = HomeIssueDay::latest($now);
        $from = $this->firstDay();

        if ($from === null) {
            return self::FAILURE;
        }

        if ($from->greaterThan($latest)) {
            $this->error(sprintf(
                'Day %s is not over yet: it runs to %s.',
                $from->toDateString(),
                HomeIssueDay::window($from)->end->toDateTimeString(),
            ));

            return self::FAILURE;
        }

        $boundary = HomeIssueDay::window($from)->start;

        // An issue reaching back past the boundary reports days this rebuild would not touch again;
        // dropping it would lose them, keeping it would report its later days twice.
        $straddling = HomeIssue::query()
            ->where('window_start', '<', $boundary)
            ->where('published_at', '>', $boundary)
            ->orderBy('window_start')
            ->first();

        if ($straddling !== null) {
            $this->error(sprintf(
                'Issue %s (No. %d) covers %s – %s, which reaches back past %s; rebuild from %s instead.',
                $straddling->issue_date->toDateString(),
                $straddling->number,
                $straddling->window_start->toDateTimeString(),
                $straddling->published_at->toDateTimeString(),
                $from->toDateString(),
                HomeIssueDay::of(CarbonImmutable::parse($straddling->window_start))->toDateString(),
            ));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            $this->rebuild($publish, $from, $latest, $boundary, $dryRun);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return self::SUCCESS;
    }

    private function rebuild(PublishHomeIssue $publish, CarbonImmutable $from, CarbonImmutable $latest, CarbonImmutable $boundary, bool $dryRun): void
    {
        // Every issue whose window ends after the boundary starts on or after it (the straddling
        // check above), so this is exactly the stretch being rebuilt. Items go with the cascade.
        $dropped = HomeIssue::query()->where('published_at', '>', $boundary)->delete();

        $this->info(sprintf('%s %d issues from %s on.', $dryRun ? 'Would drop' : 'Dropped', $dropped, $from->toDateString()));

        $published = 0;
        $blank = 0;

        for ($day = $from; $day->lessThanOrEqualTo($latest); $day = $day->addDay()) {
            $window = HomeIssueDay::window($day);
            // As of the morning the issue would have gone out, as a backfill is (PublishHomeIssueCommand).
            $issue = $publish($window->end, null, $window);

            if ($issue === null) {
                $this->nothingQualified($window);
                $blank++;

                continue;
            }

            $this->reportIssue($dryRun ? 'Would publish' : 'Published', $issue);
            $published++;
        }

        $this->info(sprintf(
            '%s %s – %s: %d issues, %d blank days.%s',
            $dryRun ? 'Dry run of' : 'Rebuilt',
            $from->toDateString(),
            $latest->toDateString(),
            $published,
            $blank,
            $dryRun ? ' Nothing was written.' : '',
        ));
    }

    /** The day the rebuild starts on, or null with the reason printed. */
    private function firstDay(): ?CarbonImmutable
    {
        $from = $this->option('from');

        if ($from !== null) {
            $day = $this->calendarDay((string) $from);

            if ($day === null) {
                $this->error(sprintf('--from must name a day on the calendar as YYYY-MM-DD; got "%s".', $from));
            }

            return $day;
        }

        $earliest = HomeIssue::query()->orderBy('window_start')->first();

        if ($earliest === null) {
            $this->error('Nothing to rebuild: no issue is published. Name a day with --from, or fill days in with openpne:publish-home-issue --date.');

            return null;
        }

        return HomeIssueDay::of(CarbonImmutable::parse($earliest->window_start));
    }
}
