<?php

namespace App\Files;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Decodes stored image bytes as a still image: an animated source is reduced to its
 * first frame, which is what OpenPNE 3 produced and what every consumer here wants.
 *
 * Decoding an animation holds every frame as a full-canvas pixel buffer, so the cost is
 * frames x width x height however small the encoded file is — and GD allocates those
 * buffers outside PHP's memory_limit, which leaves nothing bounding them. The upload
 * rules bound one frame's dimensions, not the frame count, so the animation has to be
 * dropped here. Original-size delivery streams the stored bytes without decoding, so an
 * uploaded animation still plays there.
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
