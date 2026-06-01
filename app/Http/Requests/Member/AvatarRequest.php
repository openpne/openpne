<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class AvatarRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Bound the pixel dimensions, not just the file size: the thumbnail decoder
        // allocates width*height*4 bytes, so a small file declaring huge dimensions is
        // a memory-exhaustion (decompression-bomb) vector.
        $max = (int) config('openpne.images.max_upload_dimension');

        return [
            // Raster image only: `image` rejects non-images; `mimes` further drops SVG
            // (scriptable) and other exotic types so only deliverable avatars get in.
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,gif,webp', "dimensions:max_width={$max},max_height={$max}", 'max:5120'],
        ];
    }
}
