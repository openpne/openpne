<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\GdImageProcessor;
use App\Files\ImageProcessor;
use InvalidArgumentException;
use Tests\TestCase;

class ImageProcessorResolutionTest extends TestCase
{
    private function processorFor(?string $configured, ?string $legacyDriver = null): ImageProcessor
    {
        config(['openpne.images.processor' => $configured, 'openpne.images.legacy_driver' => $legacyDriver]);
        $this->app->forgetInstance(ImageProcessor::class);

        return $this->app->make(ImageProcessor::class);
    }

    public function test_gd_resolves_to_the_in_process_processor(): void
    {
        $this->assertInstanceOf(GdImageProcessor::class, $this->processorFor('gd'));
    }

    /**
     * The removed imagick and vips options are the cases that matter: they used to resolve, so an
     * env that still names one must fail rather than quietly become GD.
     */
    public function test_an_unsupported_processor_throws_rather_than_falling_back(): void
    {
        foreach (['imagick', 'vips', 'gd ', '', null] as $configured) {
            try {
                $this->processorFor($configured);
                $this->fail('Expected '.var_export($configured, true).' to be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('openpne.images.processor', $e->getMessage());
            }
        }
    }

    public function test_a_lingering_image_driver_setting_is_refused_whatever_its_value(): void
    {
        foreach (['imagick', 'gd'] as $legacy) {
            try {
                $this->processorFor('gd', $legacy);
                $this->fail("Expected OPENPNE_IMAGE_DRIVER={$legacy} to be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('OPENPNE_IMAGE_DRIVER', $e->getMessage());
                $this->assertStringContainsString('OPENPNE_IMAGE_PROCESSOR', $e->getMessage());
            }
        }
    }
}
