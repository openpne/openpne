<?php

namespace App\Http\Requests\Member;

use App\Rules\NewEmailAddress;
use Illuminate\Foundation\Http\FormRequest;

class InviteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => NewEmailAddress::rules(),
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
