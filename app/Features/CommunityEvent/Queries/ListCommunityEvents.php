<?php

namespace App\Features\CommunityEvent\Queries;

use App\Models\Community;
use App\Models\CommunityEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A community's events (OpenPNE 3 communityEvent/listCommunity): most recently active first.
 * updated_at is the activity key — a new comment touches it (CreateEventComment), so an event with
 * fresh replies sorts above an untouched one. This is OpenPNE 3's order; it is not open_date order.
 */
class ListCommunityEvents
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, CommunityEvent> */
    public function __invoke(Community $community, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $community->events()
            ->withCount('comments')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
