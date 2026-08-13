<?php

namespace App\Policies;

use App\Features\GroupTopic\GroupTopicAccess;
use App\Models\GroupTopic;
use App\Models\Member;

/**
 * Topic-level gates (auto-discovered for GroupTopic), delegating to GroupTopicAccess. The
 * board-level gates (view a group's board, post a topic) key on Group, so the controller
 * calls GroupTopicAccess directly for those — as the group adapter does for membership.
 */
class GroupTopicPolicy
{
    public function view(Member $viewer, GroupTopic $topic): bool
    {
        return GroupTopicAccess::canViewTopic($topic, $viewer);
    }

    /** OpenPNE 3's edit privilege covers both editing and deleting a topic. */
    public function update(Member $actor, GroupTopic $topic): bool
    {
        return GroupTopicAccess::canEditTopic($topic, $actor);
    }

    public function delete(Member $actor, GroupTopic $topic): bool
    {
        return GroupTopicAccess::canEditTopic($topic, $actor);
    }
}
