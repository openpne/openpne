<?php

namespace App\Http\Requests\Diary;

use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\DiaryVisibility;
use App\Http\Requests\Concerns\PostImageRules;
use App\Rules\MaxBytes;
use App\Support\BodyFormat;
use App\Support\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiaryRequest extends FormRequest
{
    // Bounded by bytes, not characters: the body lives in a TEXT column (65535 bytes), and MySQL
    // rejects anything longer at insert time. The cap equals the column size, so no migrated value
    // can be locked out of re-editing.
    private const BODY_MAX_BYTES = 65535;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->textRules(), ...PostImageRules::rules()];
    }

    /**
     * The text fields, shared with editing (UpdateDiaryRequest).
     *
     * @return array<string, mixed>
     */
    protected function textRules(): array
    {
        return [
            'title' => ['required', 'string'],
            'body' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
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
