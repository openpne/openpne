<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Models\CommunityEvent;
use App\Models\Member;

class UpdateEvent
{
    public function __invoke(Member $actor, CommunityEvent $event, CommunityEventFormData $data): CommunityEvent
    {
        if (! CommunityEventAccess::canEditEvent($event, $actor)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotEdit);
        }

        // OpenPNE 3 bumps event_updated_at only when the name or body actually changes (preSave
        // isEventModified). The save bumps updated_at too (the board ordering key) whenever any field
        // changed, so an edited event rises on the board.
        $contentChanged = $event->name !== $data->name || $event->body !== $data->body;
        $event->fill([
            'name' => $data->name,
            'body' => $data->body,
            'open_date' => $data->open_date,
            'open_date_comment' => $data->open_date_comment,
            'area' => $data->area,
            'application_deadline' => $data->application_deadline,
            'capacity' => $data->capacity,
        ]);
        if ($contentChanged) {
            $event->event_updated_at = now();
        }
        $event->save();

        return $event;
    }
}
