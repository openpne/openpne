<?php

namespace App\Features\Block\Queries;

use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListBlocks
{
    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(Member $blocker, int $perPage = 20): LengthAwarePaginator
    {
        // The Modern block list renders each blocked member's avatar; eager-load it to avoid N+1.
        return $blocker->blocksMade()->with('avatar.file')->paginate($perPage);
    }
}
