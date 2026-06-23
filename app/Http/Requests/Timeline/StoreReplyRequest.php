<?php

namespace App\Http\Requests\Timeline;

use Illuminate\Foundation\Http\FormRequest;

class StoreReplyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // OpenPNE 3 activity_data.body is string(140); a reply has no image or audience of its own.
            'body' => ['required', 'string', 'max:140'],
        ];
    }
}
