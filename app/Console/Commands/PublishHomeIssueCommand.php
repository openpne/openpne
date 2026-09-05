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
 * See docs/internals/home-issues.md, "Schedule and idempotency".
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

        // As of the boundary the window closes on, not the clock: the calendar band looks forward
        // from here.
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
     * A day whose window has not closed yet is refused, today included: today's 06:00 boundary lies
     * ahead.
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
     * A chained issue's window is as long as the gap it closed, so a named day can fall inside a
     * stretch already reported — which the unique on `issue_date` cannot see.
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
