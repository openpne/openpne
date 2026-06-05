<?php

namespace App\Features\Community\Queries;

use App\Models\Community;

class ShowCommunity
{
    /**
     * A community by id for the top page, with its confirmed-member count. Phase A: any
     * authenticated member may view any community (membership gates joining, not visibility),
     * so there is no per-viewer filter.
     */
    public function __invoke(int $communityId): ?Community
    {
        return Community::query()->withCount('members')->find($communityId);
    }
}
