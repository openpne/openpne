<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\Member;

class CreateEvent
{
    public function __invoke(Member $author, Community $community, CommunityEventFormData $data): CommunityEvent
    {
        if (! CommunityEventAccess::canPostEvent($community, $author)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotPost);
        }

        // event_updated_at starts at creation time (OpenPNE 3 sets it whenever name/body change,
        // which a fresh event does).
        return $community->events()->create([
            'member_id' => $author->getKey(),
            'name' => $data->name,
            'body' => $data->body,
            'open_date' => $data->open_date,
            'open_date_comment' => $data->open_date_comment,
            'area' => $data->area,
            'application_deadline' => $data->application_deadline,
            'capacity' => $data->capacity,
            'event_updated_at' => now(),
        ]);
    }
}
