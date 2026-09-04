<?php

namespace App\Files;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Why an animation must be dropped before it is decoded, and what each driver can do about it, is in
 * [security.md](../../docs/internals/security.md) § Decoding an upload.
 */
class StillImageDecoder
{
    public function __construct(private readonly ImageManager $manager) {}

    public function decode(string $bytes): ImageInterface
    {
        $image = $this->manager->decode($bytes);

        // Only reached under Imagick, which cannot drop the frames before they are
        // allocated (see FilesServiceProvider) and so collapses them after the fact.
        return $image->isAnimated() ? $image->removeAnimation() : $image;
    }
}
