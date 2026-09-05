<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Features\Home\HomeItemGate;
use App\Models\HomeIssueItem;
use Illuminate\Database\Eloquent\Model;

/**
 * One ledger row that survived {@see HomeItemGate}. `extra` is what the serializer cannot read off
 * the source: a talk burst is a stretch of a room rather than a record, resolved once by the gate,
 * which had to read the stretch anyway.
 */
final readonly class HydratedItem
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(
        public HomeIssueItem $item,
        public Model $source,
        public array $extra = [],
    ) {}
}
