<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class AvatarRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Raster image only: `image` rejects non-images; `mimes` further drops SVG
            // (scriptable) and other exotic types so only deliverable avatars get in.
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,gif,webp', 'max:5120'],
        ];
    }
}
