<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
use App\Files\ImageSpec;

class GdImageProcessorContractTest extends ImageProcessorContractTestCase
{
    protected function processor(): ImageProcessor
    {
        config(['openpne.images.processor' => 'gd']);
        $this->app->forgetInstance(ImageProcessor::class);

        return $this->app->make(ImageProcessor::class);
    }

    protected function appliesOrientation(): bool
    {
        return extension_loaded('exif');
    }

    public function test_a_body_that_does_not_decode_fails_deterministically(): void
    {
        // A sound header over pixels that are not PNG data: past the preflight, refused by GD (libvips
        // would decode it to something).
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('decoded');

        $this->processor()->process($this->pngWithGarbagePixels(10, 10, 64), 'image/png', ImageSpec::canonical('png'));
    }
}
