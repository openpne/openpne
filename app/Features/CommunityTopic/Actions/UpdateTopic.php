<?php

namespace App\Features\CommunityTopic\Actions;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionFailure;
use App\Models\CommunityTopic;
use App\Models\Member;

class UpdateTopic
{
    public function __invoke(Member $actor, CommunityTopic $topic, CommunityTopicFormData $data): CommunityTopic
    {
        if (! CommunityTopicAccess::canEditTopic($topic, $actor)) {
            throw new CommunityTopicActionException(CommunityTopicActionFailure::CannotEdit);
        }

        // OpenPNE 3 bumps topic_updated_at only when the name or body actually changes. The save
        // bumps updated_at too (the board ordering key), so an edited topic rises on the board.
        $contentChanged = $topic->name !== $data->name || $topic->body !== $data->body;

        $topic->name = $data->name;
        $topic->body = $data->body;
        if ($contentChanged) {
            $topic->topic_updated_at = now();
        }
        $topic->save();

        return $topic;
    }
}
