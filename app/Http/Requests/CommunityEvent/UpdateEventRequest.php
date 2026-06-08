<?php

namespace App\Http\Requests\CommunityEvent;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Files\PostImages;
use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Contracts\Validation\Validator;

/**
 * Edit an event. Edit authority (the author while still a member, or a community admin) is checked in
 * authorize() before validation, so a non-editor's invalid payload gets the same 404 as a valid one.
 * Editing keeps the validation of creating, except the open date may stay in the past, and it adds
 * image slot management (new uploads into free slots, remove_images[] for removals).
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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'remove_images' => ['array'],
            'remove_images.*' => ['integer'],
        ];
    }

    /** Editing keeps the original open date even if it is now in the past (OpenPNE 3 validateOpenDate is create-only). */
    protected function openDateRules(): array
    {
        return ['required', 'date_format:Y-m-d'];
    }

    /**
     * Keep the inherited deadline ≤ open_date check and add the image cross-field cap: the images
     * kept (current minus the ones being removed) plus the new uploads may not exceed MAX_IMAGES.
     * remove_images ids that aren't this event's are ignored, so a bogus id can't inflate the count.
     */
    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $event = $this->route('event');
            if (! $event instanceof CommunityEvent) {
                return;
            }

            $currentIds = $event->images()->pluck('id')->all();
            // array_unique first: a crafted remove_images=[id, id] must not count one image twice
            // and so undercount what is kept, slipping the cap.
            $removing = array_unique(array_intersect(array_map('intval', (array) $this->input('remove_images', [])), $currentIds));
            $kept = count($currentIds) - count($removing);

            if ($kept + count($this->file('images', [])) > PostImages::MAX_IMAGES) {
                $validator->errors()->add('images', __('An event can have at most :max images.', ['max' => PostImages::MAX_IMAGES]));
            }
        });
    }
}
