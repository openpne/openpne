<?php

namespace App\Features\GroupEvent\Actions;

use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupEvent\GroupEventAccess;
use App\Models\GroupEvent;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class ToggleParticipation
{
    /**
     * A full event blocks joining only. Lock the event row so the capacity check and the insert
     * cannot race two joins past the cap.
     *
     * @return bool the member's participation state after the toggle (true = now joined)
     */
    public function __invoke(Member $member, GroupEvent $event): bool
    {
        if (! GroupEventAccess::canParticipate($event, $member)) {
            throw new GroupEventActionException(GroupEventActionFailure::NotMember);
        }

        return DB::transaction(function () use ($member, $event): bool {
            $locked = GroupEvent::whereKey($event->getKey())->lockForUpdate()->first();

            return $this->apply($member, $locked);
        });
    }

    /**
     * The caller must already be inside a transaction holding $event's row lock; the capacity and
     * membership predicates query under it, so they cannot race a concurrent join.
     *
     * @return bool the member's participation state after the toggle (true = now joined)
     */
    public function apply(Member $member, GroupEvent $event): bool
    {
        // OpenPNE 3 checks closed/expired before the join-or-leave branch, so both block either
        // direction.
        if ($event->isClosed()) {
            throw new GroupEventActionException(GroupEventActionFailure::EventClosed);
        }
        if ($event->isExpired()) {
            throw new GroupEventActionException(GroupEventActionFailure::EventExpired);
        }

        if ($event->isParticipant($member)) {
            $event->participants()->detach($member);

            return false;
        }

        if ($event->isFull()) {
            throw new GroupEventActionException(GroupEventActionFailure::EventAtCapacity);
        }

        $event->participants()->attach($member);

        return true;
    }
}
