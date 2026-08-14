<?php

namespace App\Http\Requests\DirectMessage;

use Illuminate\Foundation\Http\FormRequest;

class MarkConversationReadRequest extends FormRequest
{
    /**
     * The id of the last message the client rendered. Only its shape is checked here — whether it
     * names a message of this conversation is the action's question, and answering it needs both
     * members.
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
