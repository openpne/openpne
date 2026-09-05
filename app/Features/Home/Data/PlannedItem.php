<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use Carbon\CarbonImmutable;

/**
 * One candidate the publisher is holding: the ledger row it would write, before a rank is put on it.
 * `createdAt` is here for the merge alone — stories arrive from four separate queries and are ranked
 * together, so the tiebreak compares a value none of them can order across.
 */
final readonly class PlannedItem
{
    /** @param  array<string, int|string>  $stats ranking provenance, frozen into the ledger row */
    public function __construct(
        public string $sourceType,
        public int $sourceId,
        public int $score,
        public array $stats,
        public CarbonImmutable $createdAt,
    ) {}

    public function ref(): SourceRef
    {
        return new SourceRef($this->sourceType, $this->sourceId);
    }
}
