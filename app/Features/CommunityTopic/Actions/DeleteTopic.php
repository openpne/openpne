<?php

namespace App\Features\CommunityTopic\Actions;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionFailure;
use App\Models\CommunityTopic;
use App\Models\Member;

class DeleteTopic
{
    public function __invoke(Member $actor, CommunityTopic $topic): void
    {
        if (! CommunityTopicAccess::canEditTopic($topic, $actor)) {
            throw new CommunityTopicActionException(CommunityTopicActionFailure::CannotEdit);
        }

        // FK cascade removes the comments. Image File byte purge lands with the image slice
        // (no topic images exist yet).
        $topic->delete();
    }
}
