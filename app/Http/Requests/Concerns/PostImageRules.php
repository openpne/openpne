<?php

namespace App\Http\Requests\Concerns;

use App\Files\PostImages;

/**
 * Validation rules for the `images[]` upload on a community topic/comment or event/comment, shared so
 * every form enforces the same cap and decompression-bomb guard. The cap is PostImages::MAX_IMAGES.
 */
final class PostImageRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'images' => ['array', 'max:'.PostImages::MAX_IMAGES],
            'images.*' => self::imageRule(),
        ];
    }

    /**
     * The rule for a single optional image field (not an `images[]` array) — e.g. the community top
     * image. Same raster-only + decompression-bomb guard, but for one file that may be absent.
     *
     * @return array<int, mixed>
     */
    public static function single(): array
    {
        return ['nullable', ...self::imageRule()];
    }

    /**
     * One image's rules. Bound the pixel dimensions, not just the file size: the thumbnail decoder
     * allocates width*height*4 bytes, so a small file declaring huge dimensions is a
     * memory-exhaustion (decompression-bomb) vector — same guard as the avatar upload. `image`
     * rejects non-images; `mimes` further drops SVG (scriptable) and other exotic types.
     *
     * @return array<int, mixed>
     */
    private static function imageRule(): array
    {
        $max = (int) config('openpne.images.max_upload_dimension');

        return ['file', 'image', 'mimes:jpeg,png,gif,webp', "dimensions:max_width={$max},max_height={$max}", 'max:5120'];
    }
}
