<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Models\HomeIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The run of issues, newest first. Rows only: resolving every issue's sources to render a list of
 * them would cost a page of gates per row.
 */
final class ListHomeIssues
{
    /** @return LengthAwarePaginator<int, HomeIssue> */
    public function __invoke(int $perPage = 30): LengthAwarePaginator
    {
        return HomeIssue::query()
            ->orderByDesc('issue_date')
            ->paginate($perPage);
    }
}
