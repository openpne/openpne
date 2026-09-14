<?php

namespace Tests\Support;

use App\Files\ImageIntake;
use App\Files\ImageProcessor;
use App\Files\ImageSpec;
use App\Files\ProcessedImage;
use LogicException;

/** Stands in for imgproxy where a test needs `preservesAnimation()` true and never a decode. */
final class FrameKeepingProcessor implements ImageProcessor
{
    public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
    {
        throw new LogicException('This stub answers preservesAnimation() only.');
    }

    public function preservesAnimation(): bool
    {
        return true;
    }

    public function intake(): ImageIntake
    {
        return ImageIntake::imgproxy();
    }
}
