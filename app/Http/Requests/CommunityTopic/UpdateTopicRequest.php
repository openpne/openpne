<?php

namespace App\Http\Requests\CommunityTopic;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Models\CommunityTopic;
use App\Models\Member;

/**
 * Edit a topic. Shares the create rules; only the gate differs — edit authority (the author while
 * still a member, or a community admin) is checked in authorize() before validation, so a
 * non-editor's invalid payload gets the same 404 as a valid one.
 */
class UpdateTopicRequest extends StoreTopicRequest
{
    public function authorize(): bool
    {
        $topic = $this->route('topic');
        $viewer = $this->user();
        if (! $topic instanceof CommunityTopic || ! $viewer instanceof Member
            || ! CommunityTopicAccess::canEditTopic($topic, $viewer)) {
            abort(404);
        }

        return true;
    }
}
