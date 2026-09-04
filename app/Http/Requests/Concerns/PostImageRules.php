<?php

namespace App\Http\Requests\Concerns;

use App\Files\PostImages;

/** Shared by every form that takes an `images[]` upload, so the cap and the decompression-bomb guard cannot diverge. */
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
     * Human field names for the images[] errors: without these a per-file failure reads as
     * "images.0" in the message the shared picker surfaces.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return ['images' => __('Images'), 'images.*' => __('Images')];
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
     * Pixel dimensions are bounded as well as the file size: the thumbnail decoder allocates
     * width*height*4 bytes, so a small file declaring huge dimensions is a decompression bomb. `mimes`
     * drops SVG, which can carry script.
     *
     * @return array<int, mixed>
     */
    private static function imageRule(): array
    {
        $max = (int) config('openpne.images.max_upload_dimension');

        return ['file', 'image', 'mimes:jpeg,png,gif,webp', "dimensions:max_width={$max},max_height={$max}", 'max:5120'];
    }
}
