<?php

namespace App\Http\Requests\GroupTopic;

use App\Features\GroupTopic\GroupTopicAccess;
use App\Files\ImageEdit;
use App\Files\PostImages;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Contracts\Validation\Validator;

/**
 * Edit a topic. Edit authority (the author while still a member, or a group admin) is checked in
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
        if (! $topic instanceof GroupTopic || ! $viewer instanceof Member
            || ! GroupTopicAccess::canEditTopic($topic, $viewer)) {
            abort(404);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->textRules(),
            ...PostImageRules::rules(),
            'remove_images' => ['array'],
            'remove_images.*' => ['integer'],
        ];
    }

    /** Cross-field cap: the images kept after the edit plus the new uploads may not exceed MAX_IMAGES. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $topic = $this->route('topic');
            if (! $topic instanceof GroupTopic) {
                return;
            }

            if (ImageEdit::fromRequest($this)->exceedsCap($topic->images()->pluck('id')->all())) {
                $validator->errors()->add('images', __('A %topic% can have at most :max images.', ['max' => PostImages::MAX_IMAGES]));
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }
}
