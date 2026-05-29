<?php

namespace App\Http\Requests\Diary;

use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreDiaryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'visibility' => ['required', (new Enum(Visibility::class))->except([Visibility::Open])],
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
