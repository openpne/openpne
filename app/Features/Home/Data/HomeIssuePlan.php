<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Features\Home\HomeIssueSection;

/**
 * What an issue would hold, decided but not yet written — the whole of the publisher's reading, so
 * a dry run and a publish answer from the same pass rather than from two.
 */
final readonly class HomeIssuePlan
{
    /**
     * @param  array<string, list<PlannedItem>>  $sections  keyed by {@see HomeIssueSection} value, in rank order
     * @param  SourceRef|null  $ignoredPin  a pin that did not survive the eligibility it is held to
     */
    public function __construct(
        public string $issueDate,
        public HomeIssueWindow $window,
        public array $sections,
        public ?SourceRef $ignoredPin = null,
    ) {}

    /** @return list<PlannedItem> */
    public function items(HomeIssueSection $section): array
    {
        return $this->sections[$section->value] ?? [];
    }

    public function count(HomeIssueSection $section): int
    {
        return count($this->items($section));
    }

    /**
     * Whether nothing happened worth an issue (休刊).
     *
     * Every section counts as a trigger except the calendar: upcoming events are listed until they
     * happen, so an issue triggered by them alone would come out every day of a quiet month saying
     * the same thing. Phrased as an exclusion rather than a list, so a section added later triggers
     * by default — publishing a thin issue is the recoverable mistake, never publishing is not.
     */
    public function isBlank(): bool
    {
        foreach (HomeIssueSection::cases() as $section) {
            if ($section !== HomeIssueSection::UpcomingEvents && $this->count($section) > 0) {
                return false;
            }
        }

        return true;
    }
}
