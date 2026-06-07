<?php

namespace App\Features\CommunityTopic\Queries;

use App\Models\CommunityTopic;

class ShowTopic
{
    /**
     * A topic by id with its author and community for the show page. Read access (the community's
     * topic_read_access) is enforced by the controller via CommunityTopicAccess; this only loads.
     */
    public function __invoke(int $topicId): ?CommunityTopic
    {
        return CommunityTopic::query()->with(['member', 'community', 'images.file'])->find($topicId);
    }
}
