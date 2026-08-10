<?php

namespace App\Http\Requests\Timeline;

use App\Http\Requests\Concerns\MentionRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreReplyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => MentionRules::normalizeNewlines($this->input('body'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // OpenPNE 3 activity_data.body is string(140); a reply has no image or audience of its own.
            'body' => ['required', 'string', 'max:140'],
            ...MentionRules::rules(),
        ];
    }

    /** @return list<array{member_id: int, offset: int, length: int}> */
    public function toMentions(): array
    {
        return MentionRules::normalize($this->validated('mentions', []));
    }
}
