<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/** Carries nothing: the viewer's own hint is the only one it can dismiss. */
class DismissRowActionsHintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
