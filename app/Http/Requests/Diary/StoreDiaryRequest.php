<?php

namespace App\Http\Requests\Diary;

use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\DiaryVisibility;
use App\Http\Requests\Concerns\PostImageRules;
use App\Support\Visibility;
use Illuminate\Foundation\Http\FormRequest;

class StoreDiaryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->textRules(), ...PostImageRules::rules()];
    }

    /**
     * The text fields, shared with editing (UpdateDiaryRequest).
     *
     * No max length: OpenPNE 3 diary.title/body are TEXT with no validator limit.
     * Capping here would lock out re-editing of long migrated content.
     *
     * @return array<string, mixed>
     */
    protected function textRules(): array
    {
        return [
            'title' => ['required', 'string'],
            'body' => ['required', 'string'],
            'visibility' => ['required', DiaryVisibility::rule()],
        ];
    }

    public function toData(): DiaryFormData
    {
        $validated = $this->validated();

        return new DiaryFormData(
            title: $validated['title'],
            body: $validated['body'],
            visibility: Visibility::from($validated['visibility']),
        );
    }
}
