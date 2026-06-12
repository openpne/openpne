<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class InviteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
