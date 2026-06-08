<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class ToggleParticipation
{
    /**
     * Join or leave an event's roster (OpenPNE 3 toggleEventMember). A closed or expired event
     * freezes the roster in both directions; a full event blocks only joining. Lock the event row so
     * the capacity check and the insert cannot race two joins past the cap.
     *
     * @return bool the member's participation state after the toggle (true = now joined)
     */
    public function __invoke(Member $member, CommunityEvent $event): bool
    {
        if (! CommunityEventAccess::canParticipate($event, $member)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::NotMember);
        }

        return DB::transaction(function () use ($member, $event): bool {
            $locked = CommunityEvent::whereKey($event->getKey())->lockForUpdate()->first();

            // OpenPNE 3 checks closed/expired before the join-or-leave branch, so both block either
            // direction.
            if ($locked->isClosed()) {
                throw new CommunityEventActionException(CommunityEventActionFailure::EventClosed);
            }
            if ($locked->isExpired()) {
                throw new CommunityEventActionException(CommunityEventActionFailure::EventExpired);
            }

            if ($locked->isParticipant($member)) {
                $locked->participants()->detach($member);

                return false;
            }

            if ($locked->isFull()) {
                throw new CommunityEventActionException(CommunityEventActionFailure::EventAtCapacity);
            }

            $locked->participants()->attach($member);

            return true;
        });
    }
}
