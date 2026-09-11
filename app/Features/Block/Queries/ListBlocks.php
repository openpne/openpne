<?php

namespace App\Features\Block\Queries;

use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListBlocks
{
    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(Member $blocker, int $perPage = 20): LengthAwarePaginator
    {
        return $blocker->blocksMade()->with('avatar.file')
            ->orderByPivot('created_at', 'desc')
            ->orderByPivot('blocked_id', 'desc')
            ->paginate($perPage);
    }
}
