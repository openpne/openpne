<?php

namespace App\Policies;

use App\Features\GroupEvent\GroupEventAccess;
use App\Models\GroupEvent;
use App\Models\Member;

/**
 * Event-level gates (auto-discovered for GroupEvent), delegating to GroupEventAccess. The
 * board-level gates (view a community's events, create an event) key on Group, so the controller
 * calls GroupEventAccess directly for those.
 */
class GroupEventPolicy
{
    public function view(Member $viewer, GroupEvent $event): bool
    {
        return GroupEventAccess::canViewEvent($event, $viewer);
    }

    /** OpenPNE 3's edit privilege covers both editing and deleting an event. */
    public function update(Member $actor, GroupEvent $event): bool
    {
        return GroupEventAccess::canEditEvent($event, $actor);
    }

    public function delete(Member $actor, GroupEvent $event): bool
    {
        return GroupEventAccess::canEditEvent($event, $actor);
    }
}
