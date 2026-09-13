<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\ImageProcessor;

class GdImageProcessorContractTest extends ImageProcessorContractTestCase
{
    protected function processor(): ImageProcessor
    {
        config(['openpne.images.processor' => 'gd']);
        $this->app->forgetInstance(ImageProcessor::class);

        return $this->app->make(ImageProcessor::class);
    }
}
