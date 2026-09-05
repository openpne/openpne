<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Features\Home\HomeIssueSection;

/**
 * An issue's ledger as it survives for one reader: a section here is shorter than the one that was
 * written, because a dropped row leaves no placeholder and no count of what was withheld.
 */
final readonly class HydratedIssue
{
    /** @param  array<string, list<HydratedItem>>  $sections  keyed by {@see HomeIssueSection} value */
    public function __construct(public array $sections) {}

    /** @return list<HydratedItem> */
    public function items(HomeIssueSection $section): array
    {
        return $this->sections[$section->value] ?? [];
    }
}
