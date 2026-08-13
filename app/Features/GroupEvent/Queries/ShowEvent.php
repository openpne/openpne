<?php

namespace App\Features\GroupEvent\Queries;

use App\Models\GroupEvent;

class ShowEvent
{
    /**
     * An event by id with its author and community for the show page. Read access (the community's
     * topic_read_access) is enforced by the controller via GroupEventAccess; this only loads.
     */
    public function __invoke(int $eventId): ?GroupEvent
    {
        return GroupEvent::query()->with(['member', 'group', 'images.file', 'linkCard.image'])->find($eventId);
    }
}
