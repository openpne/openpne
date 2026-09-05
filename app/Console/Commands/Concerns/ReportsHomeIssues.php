<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\HomeIssueSection;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

trait ReportsHomeIssues
{
    /**
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

    private function reportIssue(string $verb, HomeIssue $issue): void
    {
        $counts = $issue->items->countBy(fn ($item): string => $item->section->value);

        $this->reportCounts(
            $verb,
            $issue->issue_date->toDateString(),
            $issue->number,
            fn (HomeIssueSection $section): int => (int) $counts->get($section->value, 0),
        );
    }

    /** @param  callable(HomeIssueSection): int  $count */
    private function reportCounts(string $verb, string $date, int $number, callable $count): void
    {
        $this->info(sprintf('%s issue %s (No. %d): %s.', $verb, $date, $number, $this->summary($count)));
    }

    /**
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
