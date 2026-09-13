<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use App\Support\Autoplay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutoplayAnimationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['autoplay_animations' => ['required', Rule::enum(Autoplay::class)]];
    }

    public function autoplay(): Autoplay
    {
        return Autoplay::from((string) $this->validated('autoplay_animations'));
    }
}
