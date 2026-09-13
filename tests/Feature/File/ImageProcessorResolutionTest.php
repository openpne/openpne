<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\GdImageProcessor;
use App\Files\ImageProcessor;
use App\Providers\FilesServiceProvider;
use InvalidArgumentException;
use Tests\TestCase;

class ImageProcessorResolutionTest extends TestCase
{
    private function processorFor(?string $configured): ImageProcessor
    {
        config(['openpne.images.processor' => $configured]);
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

    /** Refused at boot, so a deployment still naming the old setting fails before it serves a page. */
    public function test_a_lingering_image_driver_setting_fails_the_boot_whatever_its_value(): void
    {
        foreach (['imagick', 'gd'] as $legacy) {
            config(['openpne.images.legacy_driver' => $legacy]);

            try {
                (new FilesServiceProvider($this->app))->register();
                $this->fail("Expected OPENPNE_IMAGE_DRIVER={$legacy} to be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('OPENPNE_IMAGE_DRIVER', $e->getMessage());
                $this->assertStringContainsString('OPENPNE_IMAGE_PROCESSOR', $e->getMessage());
            }
        }

        // A failed register() leaves the container without the bindings the rest of the suite resolves.
        config(['openpne.images.legacy_driver' => null]);
        (new FilesServiceProvider($this->app))->register();
    }

    public function test_a_lingering_strip_setting_fails_the_boot_whatever_its_value(): void
    {
        // env() turns the literal false into a bool, so that shape has to be refused too.
        foreach (['true', 'false', true, false] as $legacy) {
            config(['openpne.images.legacy_strip_metadata' => $legacy]);

            try {
                (new FilesServiceProvider($this->app))->register();
                $this->fail('Expected OPENPNE_STRIP_IMAGE_METADATA='.var_export($legacy, true).' to be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('OPENPNE_STRIP_IMAGE_METADATA', $e->getMessage());
            }
        }

        // A failed register() leaves the container without the bindings the rest of the suite resolves.
        config(['openpne.images.legacy_strip_metadata' => null]);
        (new FilesServiceProvider($this->app))->register();
    }
}
