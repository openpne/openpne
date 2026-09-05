<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Features\Home\HomeIssueSection;

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
     * Every section counts as a trigger except the calendar: upcoming events are listed until they
     * happen, so an issue triggered by them alone would come out every day of a quiet month. Phrased
     * as an exclusion, so a section added later triggers by default.
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
