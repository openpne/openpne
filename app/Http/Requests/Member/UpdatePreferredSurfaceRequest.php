<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use App\Support\Surface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A binary choice, deliberately without a user-facing "follow the default" option. The unset state
 * still exists in data: the controller keeps a member unset when they save the surface they are
 * already on, so a casual save never pins them.
 */
class UpdatePreferredSurfaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['preferred_surface' => ['required', Rule::enum(Surface::class)]];
    }
}
