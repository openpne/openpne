<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Features\GroupTalk\Queries\TalkSampleDigest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The stretch one issue draws from: open at the start, closed at the end.
 *
 * Consecutive issues share their boundary instant — the end is stored as the issue's `published_at`
 * and becomes the next issue's start — so a row written exactly on it must land in one issue, not
 * both. It goes with the window that closes on it, the same rule talk's digest windows follow
 * ({@see TalkSampleDigest}).
 */
final readonly class HomeIssueWindow
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    /**
     * Constrain $column to the window. Takes the column rather than assuming one, because the
     * windowed instant is the source's own `created_at` and the queries qualify it by table.
     */
    public function apply(Builder $query, string $column): Builder
    {
        return $query->where($column, '>', $this->start)->where($column, '<=', $this->end);
    }
}
