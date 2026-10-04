<?php

declare(strict_types=1);

namespace App\Files;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * What a Modern picker is told about uploads: the accept list and, while the site allows it, the
 * thresholds past which a picture is re-encoded on the member's device before it is sent. The
 * server's rules stay the gate; a picker that skipped this sends what it picked.
 */
final class ImageUploadPolicy
{
    /** @return array{accept: string, shrink: array{maxEdge: int, passthroughBytes: int, maxBytes: int, quality: float}|null} */
    public static function shared(): array
    {
        return [
            'accept' => image_upload_accept(),
            'shrink' => self::shrink(),
        ];
    }

    /**
     * The pass-through size never exceeds the upload cap, so a picture that would be refused unshrunk is
     * shrunk; `quality` is the canvas's 0..1, the config's 0..100 divided here.
     *
     * @return array{maxEdge: int, passthroughBytes: int, maxBytes: int, quality: float}|null
     */
    public static function shrink(): ?array
    {
        if (! (bool) app(SnsSettingService::class)->get(SnsSettingKey::ImageUploadBrowserShrink)) {
            return null;
        }
        $config = (array) config('openpne.images.browser_shrink');

        return [
            'maxEdge' => (int) $config['max_edge'],
            'passthroughBytes' => min((int) $config['passthrough_kb'] * 1024, UploadLimit::bytes()),
            'maxBytes' => UploadLimit::bytes(),
            'quality' => ((int) $config['jpeg_quality']) / 100,
        ];
    }
}
