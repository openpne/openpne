<?php

namespace App\Http\Requests\Timeline;

use App\Features\Timeline\Data\TimelinePostFormData;
use App\Features\Timeline\TimelineVisibility;
use App\Http\Requests\Concerns\PostImageRules;
use App\Support\Visibility;
use Illuminate\Foundation\Http\FormRequest;

class StoreTimelinePostRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // OpenPNE 3 activity_data.body is string(140).
            'body' => ['required', 'string', 'max:140'],
            'visibility' => ['required', TimelineVisibility::rule()],
            'image' => PostImageRules::single(),
        ];
    }

    public function toData(): TimelinePostFormData
    {
        $validated = $this->validated();

        return new TimelinePostFormData(
            body: $validated['body'],
            visibility: Visibility::from($validated['visibility']),
        );
    }
}
