<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use App\Support\Surface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The surface section of the member config page: the member's durable Classic/Modern choice. The
 * empty option means "follow the site/session default" — normalized to null so it resets the stored
 * preference rather than failing validation.
 */
class UpdatePreferredSurfaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('preferred_surface') === '') {
            $this->merge(['preferred_surface' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['preferred_surface' => ['nullable', Rule::enum(Surface::class)]];
    }
}
