<?php

namespace App\Http\Requests\Diary;

use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    /**
     * OpenPNE 3 right-trims the comment body (opValidatorString rtrim) before validating,
     * so a whitespace-only comment is rejected as empty rather than stored blank.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => rtrim($this->input('body'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // No max length: OpenPNE 3 comment body is TEXT with no validator limit.
            'body' => ['required', 'string'],
            ...PostImageRules::rules(),
        ];
    }
}
