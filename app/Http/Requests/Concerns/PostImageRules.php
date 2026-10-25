<?php

namespace App\Http\Requests\Concerns;

use App\Files\ImageProcessor;
use App\Files\PostImages;
use App\Files\UploadLimit;
use App\Rules\PostUpload;

/** Shared by every form that takes an `images[]` upload, so the caps and the decompression-bomb guard cannot diverge. */
final class PostImageRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'images' => ['array', 'max:'.PostImages::MAX_IMAGES],
            'images.*' => ['file', new PostUpload],
        ];
    }

    /**
     * The picture-only shape of rules(), for a caller whose readers show pictures and nothing else (MCP).
     *
     * @return array<string, mixed>
     */
    public static function imagesOnly(): array
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
        return ['images' => __('Attachments'), 'images.*' => __('Attachments')];
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
     * The single-slot shape of rules(): one optional upload, picture or file (the timeline's `image` wire).
     *
     * @return array<int, mixed>
     */
    public static function singleUpload(): array
    {
        return ['nullable', 'file', new PostUpload];
    }

    /**
     * The types are the processor's (SVG, which can carry script, is never among them), and the pixel
     * dimensions are bounded here only where the decode is in-process, since GD allocates
     * width*height*4 bytes of whatever a small file declares.
     *
     * @return array<int, mixed>
     */
    public static function imageRule(): array
    {
        $intake = app(ImageProcessor::class)->intake();
        $side = $intake->sideLimit();

        return [
            'file',
            'image',
            'mimetypes:'.implode(',', $intake->mimes()),
            ...($side === null ? [] : ["dimensions:max_width={$side},max_height={$side}"]),
            'max:'.UploadLimit::kilobytes(),
        ];
    }
}
