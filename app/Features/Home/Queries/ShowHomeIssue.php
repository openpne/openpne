<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Data\HydratedIssue;
use App\Features\Home\HomeItemGate;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * An issue read by one member: its ledger resolved back through every source's own gate. The reads
 * are bounded — one per source table, and the relations the gate asks about in one query each
 * ({@see ViewerRelations}) — with talk the exception, since no read describes several rooms at once.
 */
final class ShowHomeIssue
{
    public function __construct(
        private readonly HomeItemGate $gate,
        private readonly HomeIssueSources $sources,
    ) {}

    public function __invoke(Member $viewer, HomeIssue $issue): HydratedIssue
    {
        /** @var EloquentCollection<int, HomeIssueItem> $items ordered by section, then rank */
        $items = $issue->items()->get();

        $sources = $this->sources->forPage($items);
        $this->sources->warmRelations($viewer, $sources);

        $sections = [];

        foreach ($items as $item) {
            $source = $sources[(string) $item->source_type][(int) $item->source_id] ?? null;
            $resolved = $this->gate->resolve($viewer, $item, $source);

            if ($resolved !== null) {
                $sections[$item->section->value][] = $resolved;
            }
        }

        return new HydratedIssue($sections);
    }
}
