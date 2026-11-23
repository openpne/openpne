<?php

namespace App\Http\Requests\Diary;

use App\Http\Requests\Concerns\PostImageRules;
use App\Rules\MaxBytes;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    // Bytes, not characters, equal to the TEXT column MySQL enforces at insert.
    private const BODY_MAX_BYTES = 65535;

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
            'body' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
            ...PostImageRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }
}
