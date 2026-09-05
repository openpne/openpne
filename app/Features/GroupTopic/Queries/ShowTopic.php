<?php

namespace App\Features\GroupTopic\Queries;

use App\Models\GroupTopic;

class ShowTopic
{
    /** Read access is the controller's, through GroupTopicAccess; this only loads. */
    public function __invoke(int $topicId): ?GroupTopic
    {
        return GroupTopic::query()->with(['member', 'group', 'images.file', 'linkCard.image'])->find($topicId);
    }
}
