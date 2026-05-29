<?php

namespace App\Http\Requests\Block;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class BlockRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_id' => ['required', 'integer', 'exists:members,id'],
        ];
    }

    public function target(): Member
    {
        return Member::findOrFail($this->validated('target_id'));
    }
}
