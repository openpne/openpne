<?php

namespace App\Http\Requests\GroupTalk;

use Illuminate\Foundation\Http\FormRequest;

class MarkTalkReadRequest extends FormRequest
{
    /**
     * The id of the last message the client rendered. Only its shape is checked here — whether it
     * names a live message of this group is the action's question, and answering it needs the group.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'messageId' => ['required', 'integer', 'min:1'],
        ];
    }
}
