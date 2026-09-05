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
 * See docs/internals/home-issues.md, "Schedule and idempotency".
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
        // The straddling check above makes this exactly the stretch being rebuilt; items go with the
        // cascade.
        $dropping = HomeIssue::query()->where('published_at', '>', $boundary);
        $dropped = $dropping->clone()->orderBy('issue_date')->pluck('issue_date');
        $dropping->delete();

        // Dated, so an issue dropped from past the last closed day — one published by hand under an
        // older rule, say — shows up as a date the rebuild below never reaches.
        $this->info($dropped->isEmpty()
            ? sprintf('%s 0 issues.', $dryRun ? 'Would drop' : 'Dropped')
            : sprintf(
                '%s %d issues dated %s – %s.',
                $dryRun ? 'Would drop' : 'Dropped',
                $dropped->count(),
                $dropped->first()->toDateString(),
                $dropped->last()->toDateString(),
            ));

        $published = 0;
        $blank = 0;

        for ($day = $from; $day->lessThanOrEqualTo($latest); $day = $day->addDay()) {
            $window = HomeIssueDay::window($day);
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
