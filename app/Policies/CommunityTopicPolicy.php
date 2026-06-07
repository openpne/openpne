<?php

namespace App\Policies;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Models\CommunityTopic;
use App\Models\Member;

/**
 * Topic-level gates (auto-discovered for CommunityTopic), delegating to CommunityTopicAccess. The
 * board-level gates (view a community's board, post a topic) key on Community, so the controller
 * calls CommunityTopicAccess directly for those — as the community adapter does for membership.
 */
class CommunityTopicPolicy
{
    public function view(Member $viewer, CommunityTopic $topic): bool
    {
        return CommunityTopicAccess::canViewTopic($topic, $viewer);
    }

    /** OpenPNE 3's edit privilege covers both editing and deleting a topic. */
    public function update(Member $actor, CommunityTopic $topic): bool
    {
        return CommunityTopicAccess::canEditTopic($topic, $actor);
    }

    public function delete(Member $actor, CommunityTopic $topic): bool
    {
        return CommunityTopicAccess::canEditTopic($topic, $actor);
    }
}
