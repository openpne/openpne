<?php

namespace App\Http\Requests\CommunityTopic;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\CommunityTopicImages;
use App\Models\CommunityTopic;
use App\Models\Member;
use Illuminate\Contracts\Validation\Validator;

/**
 * Edit a topic. Edit authority (the author while still a member, or a community admin) is checked in
 * authorize() before validation, so a non-editor's invalid payload gets the same 404 as a valid one.
 * Editing adds new images into free slots and removes selected ones (remove_images[]); the total
 * after the edit may not exceed the cap.
 */
class UpdateTopicRequest extends StoreTopicRequest
{
    public function authorize(): bool
    {
        $topic = $this->route('topic');
        $viewer = $this->user();
        if (! $topic instanceof CommunityTopic || ! $viewer instanceof Member
            || ! CommunityTopicAccess::canEditTopic($topic, $viewer)) {
            abort(404);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->textRules(),
            ...TopicImageRules::rules(),
            'remove_images' => ['array'],
            'remove_images.*' => ['integer'],
        ];
    }

    /**
     * Cross-field cap: the images kept (current minus the ones being removed) plus the new uploads
     * may not exceed MAX_IMAGES. remove_images ids that aren't this topic's are ignored, so a
     * bogus id can't inflate the kept count downwards.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $topic = $this->route('topic');
            if (! $topic instanceof CommunityTopic) {
                return;
            }

            $currentIds = $topic->images()->pluck('id')->all();
            // array_unique first: a crafted remove_images=[id, id] must not count one image twice
            // and so undercount what is kept, slipping the cap.
            $removing = array_unique(array_intersect(array_map('intval', (array) $this->input('remove_images', [])), $currentIds));
            $kept = count($currentIds) - count($removing);

            if ($kept + count($this->file('images', [])) > CommunityTopicImages::MAX_IMAGES) {
                $validator->errors()->add('images', __('A topic can have at most :max images.', ['max' => CommunityTopicImages::MAX_IMAGES]));
            }
        });
    }
}
