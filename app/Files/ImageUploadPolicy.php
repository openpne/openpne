<?php

declare(strict_types=1);

namespace App\Files;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/** The server's rules stay the gate; a picker that skipped this sends what it picked. */
final class ImageUploadPolicy
{
    /** @return array{accept: string, shrink: array{maxEdge: int, passthroughBytes: int, maxBytes: int, quality: float, keepsFrames: bool}|null} */
    public static function shared(): array
    {
        return [
            'accept' => image_upload_accept(),
            'shrink' => self::shrink(),
        ];
    }

    /**
     * Neither threshold exceeds the upload rules' own, so a picture that would be refused unshrunk
     * is shrunk; `quality` is the canvas's 0..1, the config's 0..100 divided here.
     *
     * @return array{maxEdge: int, passthroughBytes: int, maxBytes: int, quality: float, keepsFrames: bool}|null
     */
    public static function shrink(): ?array
    {
        if (! (bool) app(SnsSettingService::class)->get(SnsSettingKey::ImageUploadBrowserShrink)) {
            return null;
        }
        $config = (array) config('openpne.images.browser_shrink');

        return [
            'maxEdge' => min((int) $config['max_edge'], UploadLimit::dimension()),
            'passthroughBytes' => self::passthroughBytes(),
            'maxBytes' => UploadLimit::bytes(),
            'quality' => (float) ((int) $config['jpeg_quality'] / 100),
            // An animated WebP is worth sending whole only where the processor keeps its frames.
            'keepsFrames' => app(ImageProcessor::class)->preservesAnimation(),
        ];
    }

    public static function passthroughBytes(): int
    {
        return min((int) config('openpne.images.browser_shrink.passthrough_kb') * 1024, UploadLimit::bytes());
    }
}
