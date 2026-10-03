<?php

declare(strict_types=1);

namespace App\Features\Home\Actions;

use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\Queries\LatestHomeIssue;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The issue the front page shows: the latest one, published first when the last 06:00 boundary has
 * passed without a run (docs/internals/home-issues.md, "Publish on read").
 */
final class PublishDueHomeIssue
{
    public function __construct(
        private readonly LatestHomeIssue $latest,
        private readonly PublishHomeIssue $publish,
    ) {}

    public function __invoke(CarbonImmutable $now): ?HomeIssue
    {
        $latest = ($this->latest)();
        $boundary = HomeIssueDay::window(HomeIssueDay::latest($now))->end;

        if ($latest !== null && ! $latest->published_at->lt($boundary)) {
            return $latest;
        }

        // Once per boundary, failure included: a blank day leaves no row to find and would be planned
        // again on every request.
        if (! Cache::add('home-issue:attempted:'.$boundary->toDateString(), true, 86400)) {
            return $latest;
        }

        try {
            return ($this->publish)($boundary) ?? $latest;
        } catch (Throwable $e) {
            report($e);

            return $latest;
        }
    }
}
