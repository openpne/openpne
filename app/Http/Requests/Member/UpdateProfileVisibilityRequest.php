<?php

namespace App\Http\Requests\Member;

use App\Features\Profile\ProfilePageVisibility;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['profile_visibility' => ['required', ProfilePageVisibility::rule()]];
    }
}
