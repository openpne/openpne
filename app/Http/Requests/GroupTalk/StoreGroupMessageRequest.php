<?php

namespace App\Http\Requests\GroupTalk;

use App\Http\Requests\Concerns\MentionRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreGroupMessageRequest extends FormRequest
{
    /**
     * Code points, not bytes: `max` measures a string with mb_strlen, the same unit the mention
     * ranges will be recorded in and the one JavaScript's Array.from() agrees with. Well inside the
     * TEXT column even at four bytes a point.
     */
    public const MAX_BODY = 5000;

    protected function prepareForValidation(): void
    {
        // Before the length check, so a CRLF body is not measured a line-break longer than the one
        // the member typed — and so a stored body's newlines match the offsets mentions will carry.
        if (is_string($this->input('body'))) {
            $this->merge(['body' => MentionRules::normalizeNewlines($this->input('body'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
        ];
    }
}
