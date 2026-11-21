<?php

namespace App\Features\Group;

use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Models\Group;
use App\Models\Member;
use App\Support\Feature;

/**
 * The one answer to which of a group's boards a viewer may open, shared by the group page's boxes and
 * the place's sections (docs/internals/modern-frame.md, "Place sections").
 */
final class GroupPlace
{
    /** @return array{talk: bool, topic: bool, event: bool} */
    public static function openSections(Group $group, Member $viewer): array
    {
        return [
            'talk' => Feature::GroupTalk->enabled() && GroupTalkAccess::canView($group, $viewer),
            'topic' => Feature::GroupTopic->enabled() && GroupTopicAccess::canViewBoard($group, $viewer),
            'event' => Feature::GroupEvent->enabled() && GroupEventAccess::canViewBoard($group, $viewer),
        ];
    }
}
