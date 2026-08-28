<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsHomeIssues;
use App\Features\Home\Actions\PublishHomeIssue;
use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\HomeIssueSection;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Publishes a day's home issue.
 *
 * Without `--date` the window chains from the previous issue and closes on the last 06:00 boundary,
 * which is the scheduled run. With one it is the named day's own stretch instead, for filling an
 * archive in — oldest day first, since the never-again ledger remembers whatever the runs before it
 * featured.
 */
class PublishHomeIssueCommand extends Command
{
    use ReportsHomeIssues;

    protected $signature = 'openpne:publish-home-issue
        {--date= : Publish a past day (YYYY-MM-DD) over its own window instead of chaining from the last issue}
        {--dry-run : Report what the issue would hold without publishing it}';

    protected $description = "Publish a day's home issue from what happened in it";

    public function handle(PublishHomeIssue $publish): int
    {
        $now = CarbonImmutable::now();
        $date = $this->option('date');
        $date = $date === null ? null : (string) $date;

        $window = $date === null ? $publish->window($now) : $this->backfillWindow($date, $now);

        if ($window === null) {
            return self::FAILURE;
        }

        // As of the morning the issue goes out — the boundary the window closes on, not the clock:
        // the calendar band looks forward from here, and a run filling in a missed morning would
        // otherwise list gatherings by the day it happened to run on.
        $asOf = $window->end;

        $existing = $publish->publishedOn($window->lastDay());
        if ($existing !== null) {
            $this->alreadyPublished($existing);

            // Re-running the schedule is the ordinary case and says so; naming a day that already
            // has an issue is a mistake, and the exit code is where a backfill script reads that.
            return $date === null ? self::SUCCESS : self::FAILURE;
        }

        if ($date !== null && ! $this->windowIsClear($date, $window)) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $plan = $publish->plan($asOf, null, $window);

            if ($plan === null) {
                $this->nothingQualified($window);

                return self::SUCCESS;
            }

            $this->reportCounts(
                'Would publish',
                $plan->issueDate,
                $publish->nextNumber(),
                fn (HomeIssueSection $section): int => $plan->count($section),
            );

            return self::SUCCESS;
        }

        $issue = $publish($asOf, null, $window);

        if ($issue === null) {
            $this->nothingQualified($window);

            return self::SUCCESS;
        }

        if (! $issue->wasRecentlyCreated) {
            $this->alreadyPublished($issue);

            return self::SUCCESS;
        }

        $this->reportIssue('Published', $issue);

        return self::SUCCESS;
    }

    /**
     * The stretch a `--date` run covers, or null with the reason printed.
     *
     * A day whose window has not closed yet is refused: its issue is the one the schedule is about
     * to publish, and dating it now would report a stretch that is still running — which is also
     * what makes "today" unavailable, since today's 06:00 boundary lies ahead.
     */
    private function backfillWindow(string $date, CarbonImmutable $now): ?HomeIssueWindow
    {
        $day = $this->calendarDay($date);

        if ($day === null) {
            $this->error(sprintf('--date must name a day on the calendar as YYYY-MM-DD; got "%s".', $date));

            return null;
        }

        $window = HomeIssueDay::window($day);

        if ($window->end->greaterThan($now)) {
            $this->error(sprintf(
                'Issue %s is not over yet: its day runs to %s.',
                $day->toDateString(),
                $window->end->toDateTimeString(),
            ));

            return null;
        }

        return $window;
    }

    /**
     * True when no published issue already reports part of $window, the reason printed when one does.
     *
     * A backfill names every day it wants, so two named days never share an instant — but a chained
     * issue's window is as long as the gap it closed (the first one reaches back
     * {@see PublishHomeIssue::FIRST_WINDOW_DAYS} days), and a day inside that stretch has already
     * been reported. Publishing it again would print the same happenings twice under two datelines,
     * which the unique on `issue_date` cannot see.
     */
    private function windowIsClear(string $date, HomeIssueWindow $window): bool
    {
        // Both bounds strict: consecutive issues share their boundary instant, and an issue that
        // ends exactly where this window opens overlaps it by nothing.
        $overlap = HomeIssue::query()
            ->where('window_start', '<', $window->end)
            ->where('published_at', '>', $window->start)
            ->orderBy('window_start')
            ->first();

        if ($overlap === null) {
            return true;
        }

        $this->error(sprintf(
            'Issue %s overlaps issue %s (No. %d), which already covers %s – %s.',
            $date,
            $overlap->issue_date->toDateString(),
            $overlap->number,
            $overlap->window_start->toDateTimeString(),
            $overlap->published_at->toDateTimeString(),
        ));

        return false;
    }
}
