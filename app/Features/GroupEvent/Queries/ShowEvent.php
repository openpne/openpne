<?php

namespace App\Features\GroupEvent\Queries;

use App\Models\GroupEvent;

class ShowEvent
{
    /** Read access is the controller's, through GroupEventAccess; this only loads. */
    public function __invoke(int $eventId): ?GroupEvent
    {
        return GroupEvent::query()->with(['member', 'group', 'images.file', 'linkCard.image'])->find($eventId);
    }
}
