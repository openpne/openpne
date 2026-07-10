<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use App\Support\AvatarColor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The badge-color section of the avatar editor. null clears the choice back to the neutral badge —
 * "no color" is a first-class option, not an error.
 */
class UpdateAvatarColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['avatar_color' => ['nullable', Rule::enum(AvatarColor::class)]];
    }
}
