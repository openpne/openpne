<?php

namespace App\Policies;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Models\CommunityEvent;
use App\Models\Member;

/**
 * Event-level gates (auto-discovered for CommunityEvent), delegating to CommunityEventAccess. The
 * board-level gates (view a community's events, create an event) key on Community, so the controller
 * calls CommunityEventAccess directly for those.
 */
class CommunityEventPolicy
{
    public function view(Member $viewer, CommunityEvent $event): bool
    {
        return CommunityEventAccess::canViewEvent($event, $viewer);
    }

    /** OpenPNE 3's edit privilege covers both editing and deleting an event. */
    public function update(Member $actor, CommunityEvent $event): bool
    {
        return CommunityEventAccess::canEditEvent($event, $actor);
    }

    public function delete(Member $actor, CommunityEvent $event): bool
    {
        return CommunityEventAccess::canEditEvent($event, $actor);
    }
}
