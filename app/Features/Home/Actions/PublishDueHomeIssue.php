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
    /** How long a failed attempt holds the boundary before the next read may try again. */
    public const RETRY_SECONDS = 600;

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

        // Once per boundary: a blank day leaves no row to find and would be planned again on every
        // request.
        $key = 'home-issue:attempted:'.$boundary->toDateString();
        if (! Cache::add($key, true, 86400)) {
            return $latest;
        }

        try {
            return ($this->publish)($boundary) ?? $latest;
        } catch (Throwable $e) {
            report($e);
            Cache::put($key, true, self::RETRY_SECONDS);

            return $latest;
        }
    }
}
