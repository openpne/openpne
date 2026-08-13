<?php

namespace App\Features\GroupTopic\Queries;

use App\Models\GroupTopic;

class ShowTopic
{
    /**
     * A topic by id with its author and group for the show page. Read access (the group's
     * topic_read_access) is enforced by the controller via GroupTopicAccess; this only loads.
     */
    public function __invoke(int $topicId): ?GroupTopic
    {
        return GroupTopic::query()->with(['member', 'group', 'images.file', 'linkCard.image'])->find($topicId);
    }
}
