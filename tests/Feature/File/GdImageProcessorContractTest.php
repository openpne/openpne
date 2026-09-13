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

    public function test_a_heic_is_not_read_however_it_is_labelled(): void
    {
        // GD has no HEIF decoder: the intake says so, and a row typed by its canonical whose bytes
        // are HEIC (uploaded under the sidecar) is refused at the decode rather than misread.
        $this->assertNull($this->processor()->intake()->canonicalFormat('image/heic'));

        $this->expectException(ImageProcessingException::class);
        $this->processor()->process($this->fixture('heic-gps-orientation.heic'), 'image/jpeg', ImageSpec::canonical('jpg'));
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
