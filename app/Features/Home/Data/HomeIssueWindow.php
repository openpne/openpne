<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Features\GroupTalk\Queries\TalkSampleDigest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The stretch one issue draws from: open at the start, closed at the end. Consecutive issues share
 * their boundary instant, so a row written exactly on it goes with the window that closes on it, the
 * same rule talk's digest windows follow ({@see TalkSampleDigest}).
 */
final readonly class HomeIssueWindow
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    public function firstDay(): CarbonImmutable
    {
        return HomeIssueDay::of($this->start);
    }

    /**
     * The last day this window covers, which is the day its issue is dated by.
     *
     * The instant before the end, not the end itself: the window is closed there, so a window that
     * shuts at 06:00 covers up to that moment and no further — asking the boundary itself would
     * name the day that is only just beginning.
     */
    public function lastDay(): CarbonImmutable
    {
        return HomeIssueDay::of($this->end->subSecond());
    }

    /**
     * Takes the column rather than assuming one, because the windowed instant is the source's own
     * `created_at` and the queries qualify it by table.
     */
    public function apply(Builder $query, string $column): Builder
    {
        return $query->where($column, '>', $this->start)->where($column, '<=', $this->end);
    }
}
