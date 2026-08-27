<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\Home\Actions\PublishHomeIssue;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\HomeIssueSection;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Publishes the day's home issue.
 *
 * There is no `--date`: a window runs from the previous issue's `published_at`, so an issue dated
 * into the past would either overlap the one after it or claim a stretch that has already been
 * reported. Re-running today is safe and says so.
 */
class PublishHomeIssueCommand extends Command
{
    protected $signature = 'openpne:publish-home-issue
        {--dry-run : Report what the issue would hold without publishing it}';

    protected $description = "Publish today's home issue from what has happened since the last one";

    public function handle(PublishHomeIssue $publish): int
    {
        $now = CarbonImmutable::now();

        $existing = $publish->publishedOn($now);
        if ($existing !== null) {
            $this->alreadyPublished($existing);

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $plan = $publish->plan($now);

            if ($plan === null) {
                $this->nothingQualified($publish->window($now));

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

        $issue = $publish($now);

        if ($issue === null) {
            $this->nothingQualified($publish->window($now));

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

    private function alreadyPublished(HomeIssue $issue): void
    {
        $this->info(sprintf('Issue %s is already published (No. %d).', $issue->issue_date->toDateString(), $issue->number));
    }

    private function nothingQualified(HomeIssueWindow $window): void
    {
        $this->info(sprintf('No issue today: nothing qualified since %s.', $window->start->toDateTimeString()));
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
