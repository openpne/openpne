<?php

namespace App\Features\GroupEvent\Queries;

use App\Models\Group;
use App\Models\GroupEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A community's events: most recently active first.
 * updated_at is the activity key — a new comment touches it (CreateEventComment), so an event with
 * fresh replies sorts above an untouched one. This is OpenPNE 3's order; it is not open_date order.
 */
class ListGroupEvents
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, GroupEvent> */
    public function __invoke(Group $group, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $group->events()
            ->withCount(['comments', 'participants'])
            ->with('member.avatar.file')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
