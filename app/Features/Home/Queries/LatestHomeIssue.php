<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Models\HomeIssue;

/**
 * The issue published most recently — where the next one's window starts.
 *
 * By time rather than by number, matching the index the schema declares for this read: a repair that
 * renumbers the archive must not change which issue the window chains from.
 */
final class LatestHomeIssue
{
    public function __invoke(): ?HomeIssue
    {
        return HomeIssue::query()->orderByDesc('published_at')->orderByDesc('id')->first();
    }
}
