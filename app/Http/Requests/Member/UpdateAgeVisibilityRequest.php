<?php

namespace App\Http\Requests\Member;

use App\Features\Profile\AgeVisibility;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The age section of the member config page: who may see the member's age. Restricted to the
 * selectable audiences (AgeVisibility::rule() drops Open — age is never web-public).
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
        return ['age_visibility' => ['required', AgeVisibility::rule()]];
    }
}
