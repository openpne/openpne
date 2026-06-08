<?php

namespace App\Features\CommunityEvent\Queries;

use App\Models\CommunityEvent;

class ShowEvent
{
    /**
     * An event by id with its author and community for the show page. Read access (the community's
     * topic_read_access) is enforced by the controller via CommunityEventAccess; this only loads.
     */
    public function __invoke(int $eventId): ?CommunityEvent
    {
        return CommunityEvent::query()->with(['member', 'community', 'images.file'])->find($eventId);
    }
}
