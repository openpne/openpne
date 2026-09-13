<?php

namespace App\Http\Requests\Member;

use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Http\FormRequest;

class AvatarRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['image' => ['required', ...PostImageRules::imageRule()]];
    }
}
