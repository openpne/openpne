<?php

namespace App\Http\Requests\CommunityEvent;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Models\CommunityEvent;
use App\Models\Member;

/**
 * Edit an event. Edit authority (the author while still a member, or a community admin) is checked in
 * authorize() before validation, so a non-editor's invalid payload gets the same 404 as a valid one.
 * Editing keeps the validation of creating, except the open date may stay in the past.
 */
class UpdateEventRequest extends StoreEventRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        $viewer = $this->user();
        if (! $event instanceof CommunityEvent || ! $viewer instanceof Member
            || ! CommunityEventAccess::canEditEvent($event, $viewer)) {
            abort(404);
        }

        return true;
    }

    /** Editing keeps the original open date even if it is now in the past (OpenPNE 3 validateOpenDate is create-only). */
    protected function openDateRules(): array
    {
        return ['required', 'date_format:Y-m-d'];
    }
}
