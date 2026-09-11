<?php

namespace App\Features\Friend\Queries;

use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListPendingRequests
{
    /** @return LengthAwarePaginator<int, Member> */
    public function __invoke(
        Member $viewer,
        PendingRequestDirection $direction,
        int $perPage = 20,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        return match ($direction) {
            PendingRequestDirection::Sent => $viewer->friendRequestsSent()->with('avatar.file')
                ->orderByPivot('created_at', 'desc')->orderByPivot('target_id', 'desc')
                ->paginate($perPage, ['*'], $pageName),
            PendingRequestDirection::Received => $viewer->friendRequestsReceived()->with('avatar.file')
                ->orderByPivot('created_at', 'desc')->orderByPivot('requester_id', 'desc')
                ->paginate($perPage, ['*'], $pageName),
        };
    }
}
