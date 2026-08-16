<?php

namespace App\Http\Requests\GroupTalk;

use Illuminate\Foundation\Http\FormRequest;

class MarkTalkReadRequest extends FormRequest
{
    /**
     * The id of the last message the client rendered. Only its shape is checked here — whether it
     * names a live message of this group is the action's question, and answering it needs the group.
     *
     * Optional, because its absence is the other thing this endpoint means: read through the latest
     * (the digest's catch-up). A value that is present but not an id is still refused — an unusable
     * id must not fall through to "mark everything read".
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
