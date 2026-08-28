<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\Home\Actions\PublishHomeIssue;
use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\HomeIssueSection;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

/**
 * Publishes a day's home issue.
 *
 * Without `--date` the window chains from the previous issue and closes now, which is the scheduled
 * run. With one it is the named day's own stretch instead, for filling an archive in — oldest day
 * first, since the never-again ledger remembers whatever the runs before it featured.
 */
class PublishHomeIssueCommand extends Command
{
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

        // A backfilled issue is as of the morning it would have gone out: the calendar band looks
        // forward from here, and looking forward from today would list gatherings that were already
        // over by the day being reported.
        $asOf = $date === null ? $now : $window->end;

        $existing = $publish->publishedOn($window->lastDay());
        if ($existing !== null) {
            $this->alreadyPublished($existing);

            // Re-running the schedule is the ordinary case and says so; naming a day that already
            // has an issue is a mistake, and the exit code is where a backfill script reads that.
            return $date === null ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $plan = $publish->plan($asOf, null, $window);

            if ($plan === null) {
                $this->nothingQualified($window);

                return self::SUCCESS;
            }

            $this->info(sprintf(
                'Would publish issue %s (No. %d): %s.',
                $plan->issueDate,
                $publish->nextNumber(),
                $this->summary(fn (HomeIssueSection $section): int => $plan->count($section)),
            ));

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

        $counts = $issue->items->countBy(fn ($item): string => $item->section->value);

        $this->info(sprintf(
            'Published issue %s (No. %d): %s.',
            $issue->issue_date->toDateString(),
            $issue->number,
            $this->summary(fn (HomeIssueSection $section): int => (int) $counts->get($section->value, 0)),
        ));

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
     * $date as a day on the site's clock, or null when it is not one.
     *
     * The round trip is the whole check: a date constructor rolls February 30th into March 2nd, so
     * parsing alone would quietly publish an issue for a day that never happened.
     */
    private function calendarDay(string $date): ?CarbonImmutable
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (InvalidFormatException) {
            return null;
        }

        return $day !== false && $day->format('Y-m-d') === $date ? $day : null;
    }

    private function alreadyPublished(HomeIssue $issue): void
    {
        $this->info(sprintf('Issue %s is already published (No. %d).', $issue->issue_date->toDateString(), $issue->number));
    }

    /** Named by the day it would have covered, never by "today": the two are not the same date. */
    private function nothingQualified(HomeIssueWindow $window): void
    {
        $this->info(sprintf(
            'No issue for %s: nothing qualified since %s.',
            $window->lastDay()->toDateString(),
            $window->start->toDateTimeString(),
        ));
    }

    /**
     * The one place the bands are named, so a dry run and a publish cannot describe the same issue
     * differently.
     *
     * @param  callable(HomeIssueSection): int  $count
     */
    private function summary(callable $count): string
    {
        $bands = [
            HomeIssueSection::Stories->value => 'stories',
            HomeIssueSection::Talk->value => 'talk',
            HomeIssueSection::Newcomers->value => 'newcomers',
            HomeIssueSection::NewGroups->value => 'new groups',
            HomeIssueSection::UpcomingEvents->value => 'upcoming events',
        ];

        return implode(', ', array_map(
            fn (HomeIssueSection $section): string => $count($section).' '.$bands[$section->value],
            HomeIssueSection::cases(),
        ));
    }
}
