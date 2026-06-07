<?php

namespace App\Features\CommunityTopic\Actions;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionFailure;
use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\Member;

class CreateTopic
{
    public function __invoke(Member $author, Community $community, CommunityTopicFormData $data): CommunityTopic
    {
        if (! CommunityTopicAccess::canPostTopic($community, $author)) {
            throw new CommunityTopicActionException(CommunityTopicActionFailure::CannotPost);
        }

        // topic_updated_at starts at creation time (OpenPNE 3 bumps it whenever name/body change,
        // which a fresh topic does); created_at = updated_at keep the board ordering sane.
        return $community->topics()->create([
            'member_id' => $author->getKey(),
            'name' => $data->name,
            'body' => $data->body,
            'topic_updated_at' => now(),
        ]);
    }
}
