<?php

namespace App\Http\Requests\GroupTalk;

use Illuminate\Foundation\Http\FormRequest;

class MarkTalkReadRequest extends FormRequest
{
    /**
     * Only the shape is checked; whether it names a live message of this group is the action's
     * question. Absence means "read through the latest", so a value that is present but not an id is
     * refused rather than falling through to mark everything read.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'messageId' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** The message read through, or null for "through the latest". */
    public function messageId(): ?int
    {
        $id = $this->validated('messageId');

        return $id === null ? null : (int) $id;
    }
}
