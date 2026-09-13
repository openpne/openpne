<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\GdImageProcessor;
use App\Files\ImageProcessor;
use App\Files\Imgproxy\ImgproxyImageProcessor;
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

    public function test_imgproxy_resolves_to_the_sidecar_once_its_address_key_and_salt_are_set(): void
    {
        config(['openpne.images.imgproxy' => ['url' => 'http://imgproxy:8080', 'key' => 'ab12', 'salt' => 'cd34', 'source_prefix' => '', 'timeout' => 20, 'spool_disk' => 'image_spool']]);

        $this->assertInstanceOf(ImgproxyImageProcessor::class, $this->processorFor('imgproxy'));
    }

    /** Each missing value is named by the env var the operator has to set, at resolution rather than on the first picture. */
    public function test_imgproxy_without_an_address_or_a_hex_secret_is_refused_by_name(): void
    {
        foreach ([
            ['OPENPNE_IMGPROXY_URL', ['url' => '', 'key' => 'ab12', 'salt' => 'cd34']],
            ['OPENPNE_IMGPROXY_URL', ['url' => 'imgproxy:8080', 'key' => 'ab12', 'salt' => 'cd34']],
            ['OPENPNE_IMGPROXY_KEY', ['url' => 'http://imgproxy:8080', 'key' => 'not-hex', 'salt' => 'cd34']],
            ['OPENPNE_IMGPROXY_SALT', ['url' => 'http://imgproxy:8080', 'key' => 'ab12', 'salt' => '']],
        ] as [$env, $values]) {
            config(['openpne.images.imgproxy' => $values + ['source_prefix' => '', 'timeout' => 20, 'spool_disk' => 'image_spool']]);

            try {
                $this->processorFor('imgproxy');
                $this->fail("Expected {$env} to be required.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($env, $e->getMessage());
            }
        }
    }

    /** At boot, like the removed settings: a site pointed at imgproxy without its secrets must not start and then 500 on the first picture. */
    public function test_imgproxy_without_its_secrets_fails_the_boot(): void
    {
        config(['openpne.images.processor' => 'imgproxy', 'openpne.images.imgproxy' => ['url' => 'http://imgproxy:8080', 'key' => '', 'salt' => 'cd34', 'source_prefix' => '', 'timeout' => 20, 'spool_disk' => 'image_spool']]);

        try {
            (new FilesServiceProvider($this->app))->register();
            $this->fail('Expected the boot to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('OPENPNE_IMGPROXY_KEY', $e->getMessage());
        }

        // A failed register() leaves the container without the bindings the rest of the suite resolves.
        config(['openpne.images.processor' => 'gd']);
        (new FilesServiceProvider($this->app))->register();
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
