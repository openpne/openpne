<?php

namespace App\Http\Requests\Diary;

use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\DiaryVisibility;
use App\Http\Requests\Concerns\PostImageRules;
use App\Support\BodyFormat;
use App\Support\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // op3 is never author-able: it exists only on bodies migrated from OpenPNE 3.
            'format' => ['sometimes', Rule::in([BodyFormat::Plain->value, BodyFormat::Markdown->value])],
        ];
    }

    public function toData(): DiaryFormData
    {
        $validated = $this->validated();

        return new DiaryFormData(
            title: $validated['title'],
            body: $validated['body'],
            visibility: Visibility::from($validated['visibility']),
            format: isset($validated['format']) ? BodyFormat::from($validated['format']) : null,
        );
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }
}
