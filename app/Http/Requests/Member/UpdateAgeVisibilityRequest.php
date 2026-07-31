<?php

namespace App\Http\Requests\Member;

use App\Features\Profile\AgeVisibility;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The age section of the Classic member config page: who may see the member's age. Restricted to
 * the audiences the member was offered (AgeVisibility::ruleFor() offers Open only while web-public
 * age is on, and Friends only while friends are on or the member already stores it).
 */
class UpdateAgeVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['age_visibility' => ['required', AgeVisibility::ruleFor($this->user())]];
    }
}
