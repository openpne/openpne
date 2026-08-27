<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Features\Home\HomeIssueSection;

/**
 * An issue's ledger as it survives for one reader: per section, the rows that resolved, in the rank
 * they were published under.
 *
 * A dropped row leaves nothing behind — no placeholder, no count of what was withheld — so a
 * section here is shorter than the one that was written, and the page is laid out from what is
 * left rather than from what was chosen.
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
