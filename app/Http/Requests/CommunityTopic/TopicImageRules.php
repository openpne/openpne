<?php

namespace App\Http\Requests\CommunityTopic;

use App\Features\CommunityTopic\CommunityTopicImages;

/**
 * Validation rules for the `images[]` upload on a topic or comment, shared so both forms enforce
 * the same cap and decompression-bomb guard. The cap is CommunityTopicImages::MAX_IMAGES.
 */
final class TopicImageRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        // Bound the pixel dimensions, not just the file size: the thumbnail decoder allocates
        // width*height*4 bytes, so a small file declaring huge dimensions is a memory-exhaustion
        // (decompression-bomb) vector — same guard as the avatar upload.
        $max = (int) config('openpne.images.max_upload_dimension');

        return [
            'images' => ['array', 'max:'.CommunityTopicImages::MAX_IMAGES],
            // Raster image only: `image` rejects non-images; `mimes` further drops SVG (scriptable)
            // and other exotic types so only deliverable images get in.
            'images.*' => ['file', 'image', 'mimes:jpeg,png,gif,webp', "dimensions:max_width={$max},max_height={$max}", 'max:5120'],
        ];
    }
}
