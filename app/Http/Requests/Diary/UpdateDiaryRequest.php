<?php

namespace App\Http\Requests\Diary;

use App\Files\PostImages;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Contracts\Validation\Validator;

/**
 * Edit a diary. Ownership is checked in authorize() before validation, so a non-owner's invalid
 * payload gets the same 404 as a valid one and doesn't leak existence. Editing adds new images into
 * free slots and removes selected ones (remove_images[]); the total after the edit may not exceed
 * the cap.
 */
class UpdateDiaryRequest extends StoreDiaryRequest
{
    public function authorize(): bool
    {
        $diary = $this->route('diary');
        $viewer = $this->user();
        if (! $diary instanceof Diary || ! $viewer instanceof Member || ! $viewer->is($diary->member)) {
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

    /**
     * Cross-field cap: the images kept (current minus the ones being removed) plus the new uploads
     * may not exceed MAX_IMAGES. remove_images ids that aren't this diary's are ignored, so a bogus
     * id can't inflate the kept count downwards.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $diary = $this->route('diary');
            if (! $diary instanceof Diary) {
                return;
            }

            $currentIds = $diary->images()->pluck('id')->all();
            // array_unique first: a crafted remove_images=[id, id] must not count one image twice
            // and so undercount what is kept, slipping the cap.
            $removing = array_unique(array_intersect(array_map('intval', (array) $this->input('remove_images', [])), $currentIds));
            $kept = count($currentIds) - count($removing);

            if ($kept + count($this->file('images', [])) > PostImages::MAX_IMAGES) {
                $validator->errors()->add('images', __('A %diary% can have at most :max images.', ['max' => PostImages::MAX_IMAGES]));
            }
        });
    }
}
