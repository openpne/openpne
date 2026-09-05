<?php

namespace App\Features\Group\Queries;

use App\Models\Group;

class ShowGroup
{
    /**
     * Any authenticated member may view any group — membership gates joining, not visibility — so
     * there is no per-viewer filter.
     */
    public function __invoke(int $groupId): ?Group
    {
        return Group::query()->withCount('members')->find($groupId);
    }
}
