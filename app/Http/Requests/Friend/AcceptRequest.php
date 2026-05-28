<?php

namespace App\Http\Requests\Friend;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class AcceptRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'requester_id' => ['required', 'integer', 'exists:members,id'],
        ];
    }

    public function requester(): Member
    {
        return Member::findOrFail($this->validated('requester_id'));
    }
}
